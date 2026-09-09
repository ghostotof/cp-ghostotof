<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Infrastructure\Http;

use App\Portfolio\Watch\Domain\Exception\VulnerabilitySourceUnavailableException;
use App\Portfolio\Watch\Domain\Service\VulnerabilitySourceInterface;
use App\Portfolio\Watch\Domain\ValueObject\KnownVulnerability;
use App\Portfolio\Watch\Domain\ValueObject\PackageCoordinates;
use App\Portfolio\Watch\Infrastructure\ReadsUntrustedArrays;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Couche anti-corruption devant l'API publique d'OSV.dev.
 *
 * L'interrogation se fait en deux temps, imposés par le fournisseur :
 * `querybatch` répond en une seule requête pour tout le périmètre, mais ne
 * renvoie que des identifiants ; le résumé, la sévérité et la version corrigée
 * demandent un appel par vulnérabilité. C'est un bon compromis dans notre cas —
 * deux cents paquets en une requête, puis un appel par faille, c'est-à-dire
 * presque toujours zéro.
 *
 * Le lien entre une faille et le paquet qui la porte tient à **l'ordre des
 * résultats** : OSV répond dans l'ordre des requêtes envoyées, sans rappeler de
 * quel paquet il s'agit. C'est fragile par nature, et c'est pourquoi un test le
 * fige.
 *
 * `max_redirects: 0` sur les deux appels : le défaut Symfony est d'en suivre
 * jusqu'à vingt, et le pod qui exécute cette analyse n'a aucune restriction de
 * sortie. Un fournisseur détourné disposerait donc d'un levier vers le réseau
 * interne. Les URL sont canoniques, s'interdire les redirections ne coûte rien.
 */
final readonly class OsvClient implements VulnerabilitySourceInterface
{
    use ReadsUntrustedArrays;

    private const string QUERY_BATCH_URL = 'https://api.osv.dev/v1/querybatch';
    private const string VULNERABILITY_URL = 'https://api.osv.dev/v1/vulns/';

    /** L'analyse tourne dans un travail planifié : elle peut prendre son temps. */
    private const float IDLE_TIMEOUT_SECONDS = 10.0;
    private const float MAX_DURATION_SECONDS = 30.0;

    /**
     * Garde-fou de la pagination. Mille vulnérabilités pour un même paquet
     * relèveraient déjà de l'invraisemblable ; au-delà, mieux vaut s'arrêter en
     * le disant que boucler indéfiniment sur une réponse inattendue.
     */
    private const int MAX_PAGES = 10;

    private const string USER_AGENT = 'cp-ghostotof (+https://github.com/ghostotof/cp-ghostotof)';

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {
    }

    public function findVulnerabilities(array $packages): array
    {
        if ([] === $packages) {
            return [];
        }

        /** @var array<string, PackageCoordinates> $findings identifiant => paquet qui l'a fait remonter */
        $findings = [];
        $this->collect($packages, [], $findings, 0);

        $vulnerabilities = [];

        foreach ($findings as $id => $package) {
            $vulnerabilities[] = $this->enrich($id, $package);
        }

        return $vulnerabilities;
    }

    /**
     * @param list<PackageCoordinates>          $packages
     * @param list<string|null>                 $pageTokens jeton de page par paquet, dans le même ordre
     * @param array<string, PackageCoordinates> $findings
     */
    private function collect(array $packages, array $pageTokens, array &$findings, int $depth): void
    {
        if ($depth >= self::MAX_PAGES) {
            $this->logger->warning('Pagination OSV interrompue : trop de pages.', ['depth' => $depth]);

            return;
        }

        $results = $this->queryBatch($packages, $pageTokens);

        $nextPackages = [];
        $nextTokens = [];

        foreach ($results as $index => $result) {
            $package = $packages[$index] ?? null;

            if (null === $package || !\is_array($result)) {
                continue;
            }

            foreach ($this->identifiersIn($result) as $id) {
                // Le premier paquet à faire remonter la faille en reste le
                // porteur : un même identifiant peut concerner plusieurs
                // paquets, mais le compter deux fois gonflerait le décompte.
                $findings[$id] ??= $package;
            }

            $token = $result['next_page_token'] ?? null;

            if (\is_string($token) && '' !== $token) {
                $nextPackages[] = $package;
                $nextTokens[] = $token;
            }
        }

        if ([] !== $nextPackages) {
            $this->collect($nextPackages, $nextTokens, $findings, $depth + 1);
        }
    }

    /**
     * @param array<mixed> $result
     *
     * @return list<string>
     */
    private function identifiersIn(array $result): array
    {
        $vulns = $result['vulns'] ?? null;

        if (!\is_array($vulns)) {
            return [];
        }

        $identifiers = [];

        foreach ($vulns as $vulnerability) {
            if (!\is_array($vulnerability)) {
                continue;
            }

            $id = $vulnerability['id'] ?? null;

            if (\is_string($id) && '' !== $id) {
                $identifiers[] = $id;
            }
        }

        return $identifiers;
    }

    /**
     * @param list<PackageCoordinates> $packages
     * @param list<string|null>        $pageTokens
     *
     * @return array<mixed> la liste des résultats, dans l'ordre des requêtes
     */
    private function queryBatch(array $packages, array $pageTokens): array
    {
        $queries = [];

        foreach ($packages as $index => $package) {
            $query = [
                'package' => ['name' => $package->name, 'ecosystem' => $package->ecosystem],
                'version' => $package->version,
            ];

            $token = $pageTokens[$index] ?? null;

            if (\is_string($token)) {
                $query['page_token'] = $token;
            }

            $queries[] = $query;
        }

        try {
            $response = $this->httpClient->request('POST', self::QUERY_BATCH_URL, [
                'timeout' => self::IDLE_TIMEOUT_SECONDS,
                'max_duration' => self::MAX_DURATION_SECONDS,
                'max_redirects' => 0,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'User-Agent' => self::USER_AGENT,
                ],
                'json' => ['queries' => $queries],
            ]);

            $statusCode = $response->getStatusCode();

            if (Response::HTTP_OK !== $statusCode) {
                throw VulnerabilitySourceUnavailableException::forUnexpectedStatus($statusCode);
            }

            $payload = $response->toArray(false);
        } catch (HttpClientExceptionInterface $exception) {
            throw VulnerabilitySourceUnavailableException::forTransportFailure($exception);
        }

        $results = $payload['results'] ?? null;

        if (!\is_array($results)) {
            throw VulnerabilitySourceUnavailableException::forUnexpectedShape();
        }

        return $results;
    }

    /**
     * Un enrichissement en échec ne fait pas disparaître la vulnérabilité : on
     * conserve l'identifiant, sans son détail. Le décompte affiché reste juste,
     * ce qui est le plus important.
     */
    private function enrich(string $id, PackageCoordinates $package): KnownVulnerability
    {
        try {
            $response = $this->httpClient->request('GET', self::VULNERABILITY_URL.rawurlencode($id), [
                'timeout' => self::IDLE_TIMEOUT_SECONDS,
                'max_duration' => self::MAX_DURATION_SECONDS,
                'max_redirects' => 0,
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => self::USER_AGENT,
                ],
            ]);

            if (Response::HTTP_OK !== $response->getStatusCode()) {
                return $this->bareFinding($id, $package);
            }

            $payload = $response->toArray(false);
        } catch (HttpClientExceptionInterface $exception) {
            $this->logger->warning('Détail de vulnérabilité indisponible.', [
                'id' => $id,
                'exception' => $exception,
            ]);

            return $this->bareFinding($id, $package);
        }

        return new KnownVulnerability(
            $id,
            $this->readStringList($payload, 'aliases'),
            $this->readString($payload, 'summary'),
            $this->severity($payload),
            $package,
            $this->fixedIn($payload, $package),
        );
    }

    private function bareFinding(string $id, PackageCoordinates $package): KnownVulnerability
    {
        $this->logger->warning('Vulnérabilité conservée sans son détail.', ['id' => $id]);

        return new KnownVulnerability($id, [], null, null, $package, null);
    }


    /**
     * OSV publie la sévérité à deux endroits : un champ propre à la base
     * d'origine, lisible (« HIGH »), et un vecteur CVSS normalisé. Le premier
     * est préféré parce qu'il s'affiche tel quel.
     *
     * @param array<mixed> $payload
     */
    private function severity(array $payload): ?string
    {
        $databaseSpecific = $payload['database_specific'] ?? null;

        if (\is_array($databaseSpecific)) {
            $severity = $this->readString($databaseSpecific, 'severity');

            if (null !== $severity) {
                return $severity;
            }
        }

        $severities = $payload['severity'] ?? null;

        if (!\is_array($severities)) {
            return null;
        }

        foreach ($severities as $severity) {
            if (\is_array($severity)) {
                $score = $this->readString($severity, 'score');

                if (null !== $score) {
                    return $score;
                }
            }
        }

        return null;
    }

    /**
     * La version corrigée à viser depuis la version installée.
     *
     * Deux pièges, observés sur un cas réel (GHSA-h7vf-5wrv-9fhv) : une même
     * faille liste les correctifs de **plusieurs paquets** — là,
     * `symfony/http-kernel` et `symfony/symfony` — et de **plusieurs branches**
     * — 4.4.50, 5.4.20, 6.0.20, 6.1.12, 6.2.6. Prendre la première entrée
     * venue reviendrait à conseiller le correctif d'un autre paquet, ou une
     * branche hors de portée : à quelqu'un en 4.0, on doit indiquer 4.4.50,
     * pas 6.2.6.
     *
     * On ne retient donc que les plages du paquet concerné, puis la plus petite
     * version corrigée qui dépasse celle installée.
     *
     * @param array<mixed> $payload
     */
    private function fixedIn(array $payload, PackageCoordinates $package): ?string
    {
        $candidates = $this->fixedVersionsFor($payload, $package);

        if ([] === $candidates) {
            return null;
        }

        usort($candidates, static fn (string $left, string $right): int => version_compare($left, $right));

        foreach ($candidates as $candidate) {
            if (version_compare($package->version, $candidate, '<')) {
                return $candidate;
            }
        }

        // Aucune version corrigée au-dessus de l'installée : on rend la plus
        // basse plutôt que rien, l'information reste utile.
        return $candidates[0];
    }

    /**
     * @param array<mixed> $payload
     *
     * @return list<string>
     */
    private function fixedVersionsFor(array $payload, PackageCoordinates $package): array
    {
        $affected = $payload['affected'] ?? null;

        if (!\is_array($affected)) {
            return [];
        }

        $versions = [];

        foreach ($affected as $entry) {
            if (!\is_array($entry) || !$this->concerns($entry, $package)) {
                continue;
            }

            $ranges = $entry['ranges'] ?? null;

            if (!\is_array($ranges)) {
                continue;
            }

            foreach ($ranges as $range) {
                if (!\is_array($range) || !\is_array($range['events'] ?? null)) {
                    continue;
                }

                /** @var array<mixed> $events */
                $events = $range['events'];

                foreach ($events as $event) {
                    if (\is_array($event)) {
                        $fixed = $this->readString($event, 'fixed');

                        if (null !== $fixed) {
                            $versions[] = $fixed;
                        }
                    }
                }
            }
        }

        return $versions;
    }

    /**
     * @param array<mixed> $entry
     */
    private function concerns(array $entry, PackageCoordinates $package): bool
    {
        $affectedPackage = $entry['package'] ?? null;

        if (!\is_array($affectedPackage)) {
            return false;
        }

        return $this->readString($affectedPackage, 'name') === $package->name;
    }
}
