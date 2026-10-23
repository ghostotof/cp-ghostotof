<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Infrastructure\Http;

use App\Portfolio\Watch\Domain\Exception\ReleaseCycleProductNotFoundException;
use App\Portfolio\Watch\Domain\Exception\ReleaseCycleSourceUnavailableException;
use App\Portfolio\Watch\Domain\Service\ExternalUrlFilter;
use App\Portfolio\Watch\Domain\Service\ReleaseCycleSourceInterface;
use App\Portfolio\Watch\Domain\Service\VersionNameFilter;
use App\Portfolio\Watch\Domain\ValueObject\ProductReleaseCycles;
use App\Portfolio\Watch\Domain\ValueObject\ReleaseCycle;
use App\Portfolio\Watch\Infrastructure\ReadsUntrustedArrays;
use DateTimeImmutable;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Couche anti-corruption devant l'API publique d'endoflife.date.
 *
 * Toute la connaissance du fournisseur est enfermée ici : l'URL, la forme du
 * JSON, ses acronymes. Ce qui en sort, ce sont des Value Objects du domaine —
 * jamais un tableau décodé, sans quoi la forme du tiers se propagerait de
 * proche en proche jusqu'à la page, et le jour du changement de fournisseur il
 * faudrait tout rouvrir.
 *
 * Deux bornes de temps, complémentaires plutôt que redondantes : `timeout`
 * limite l'inactivité entre deux fragments de réponse, `max_duration` limite
 * l'appel entier. Sans la seconde, un tiers qui répond très lentement mais
 * régulièrement immobilise le processus aussi longtemps qu'il le souhaite.
 * Elles sont posées ici, dans la requête, plutôt que dans un client scopé de
 * configuration : le comportement se lit avec le code qui en dépend, et un test
 * unitaire peut en vérifier la présence.
 */
final readonly class EndOfLifeDateClient implements ReleaseCycleSourceInterface
{
    use ReadsUntrustedArrays;

    private const string BASE_URL = 'https://endoflife.date/api/v1/products/';

    /** Inactivité maximale entre deux fragments de réponse, en secondes. */
    private const float IDLE_TIMEOUT_SECONDS = 5.0;

    /** Durée maximale de l'appel complet, en secondes. */
    private const float MAX_DURATION_SECONDS = 10.0;

    /**
     * Borne de la simple vérification d'existence (décision D10). Plus courte
     * que celle du rafraîchissement : un humain attend devant son formulaire,
     * et mieux vaut renoncer à vérifier que le faire patienter.
     */
    private const float VERIFICATION_TIMEOUT_SECONDS = 2.0;

    /**
     * Le fournisseur demande un User-Agent identifiable. On y met de quoi nous
     * joindre — le dépôt public, jamais le domaine de production.
     */
    private const string USER_AGENT = 'cp-ghostotof (+https://github.com/ghostotof/cp-ghostotof)';

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private ExternalUrlFilter $urlFilter,
        private VersionNameFilter $versionNameFilter,
    ) {
    }

    /**
     * Options communes aux deux appels. `max_redirects: 0` n'est pas une
     * optimisation : le défaut Symfony est de suivre jusqu'à 20 redirections,
     * et le pod du CronJob n'a aucune restriction de sortie — un fournisseur
     * détourné disposerait donc d'un levier vers le réseau interne. Les URL
     * appelées sont canoniques, ne suivre aucune redirection ne coûte rien.
     *
     * @return array<string, mixed>
     */
    private function requestOptions(float $idleTimeout, float $maxDuration): array
    {
        return [
            'timeout' => $idleTimeout,
            'max_duration' => $maxDuration,
            'max_redirects' => 0,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => self::USER_AGENT,
            ],
        ];
    }

    public function fetchProduct(string $slug): ProductReleaseCycles
    {
        try {
            $response = $this->httpClient->request(
                'GET',
                self::BASE_URL.rawurlencode($slug).'/',
                $this->requestOptions(self::IDLE_TIMEOUT_SECONDS, self::MAX_DURATION_SECONDS),
            );

            $statusCode = $response->getStatusCode();

            if (Response::HTTP_NOT_FOUND === $statusCode) {
                throw ReleaseCycleProductNotFoundException::forSlug($slug);
            }

            if (Response::HTTP_OK !== $statusCode) {
                throw ReleaseCycleSourceUnavailableException::forUnexpectedStatus($slug, $statusCode);
            }

            // `false` : ne pas convertir un code d'erreur HTTP en exception, il
            // est déjà traité ci-dessus.
            $payload = $response->toArray(false);
        } catch (HttpClientExceptionInterface $exception) {
            throw ReleaseCycleSourceUnavailableException::forTransportFailure($slug, $exception);
        }

        return $this->mapProduct($slug, $payload);
    }

    public function supportsProduct(string $slug): bool
    {
        try {
            $response = $this->httpClient->request(
                'GET',
                self::BASE_URL.rawurlencode($slug).'/',
                $this->requestOptions(self::VERIFICATION_TIMEOUT_SECONDS, self::VERIFICATION_TIMEOUT_SECONDS),
            );

            // getStatusCode() n'attend que les en-têtes : le corps de la
            // réponse n'est jamais téléchargé, on n'a besoin que du verdict.
            $statusCode = $response->getStatusCode();
        } catch (HttpClientExceptionInterface $exception) {
            throw ReleaseCycleSourceUnavailableException::forTransportFailure($slug, $exception);
        }

        if (Response::HTTP_NOT_FOUND === $statusCode) {
            return false;
        }

        if (Response::HTTP_OK !== $statusCode) {
            throw ReleaseCycleSourceUnavailableException::forUnexpectedStatus($slug, $statusCode);
        }

        return true;
    }

    /**
     * @param array<mixed> $payload
     */
    private function mapProduct(string $slug, array $payload): ProductReleaseCycles
    {
        $result = $payload['result'] ?? null;
        if (!\is_array($result)) {
            throw ReleaseCycleSourceUnavailableException::forUnexpectedShape($slug);
        }

        $releases = $result['releases'] ?? null;
        if (!\is_array($releases)) {
            throw ReleaseCycleSourceUnavailableException::forUnexpectedShape($slug);
        }

        $cycles = [];
        foreach ($releases as $release) {
            if (!\is_array($release)) {
                continue;
            }

            // Une entrée illisible, ou dont le nom n'a pas la forme d'un
            // numéro de version (#658), au milieu d'une réponse valide est
            // ignorée plutôt que fatale : mieux vaut afficher quatre cycles
            // sur cinq qu'une page en erreur. `mapCycle()` dit pourquoi.
            $cycle = $this->mapCycle($slug, $release);
            if (null === $cycle) {
                continue;
            }

            $cycles[] = $cycle;
        }

        $links = $result['links'] ?? null;

        return new ProductReleaseCycles(
            $slug,
            $this->readString($result, 'label') ?? $slug,
            // Le catalogue du fournisseur est un jeu de données ouvert : ce lien
            // n'est pas une valeur de confiance, et il finit dans un `href` de
            // page publique. Filtré ici, à la frontière — c'est le rôle même de
            // cette classe.
            $this->urlFilter->keepIfSafe(\is_array($links) ? $this->readString($links, 'html') : null),
            $cycles,
        );
    }

    /**
     * Le nom du cycle et celui de sa dernière version sont du texte du jeu de
     * données ouvert, lu jusque par le modèle des clients MCP : seule une forme
     * de numéro de version passe (issue #658). Un nom refusé fait tomber le
     * cycle, qu'on ne saurait plus rapprocher de la version en service ; une
     * dernière version refusée tombe seule.
     *
     * Un refus est journalisé à part d'une entrée illisible : il peut être le
     * signe d'un jeu de données compromis, que l'opérateur doit pouvoir
     * repérer. Le journal porte le slug, jamais la valeur refusée.
     *
     * @param array<mixed> $release
     */
    private function mapCycle(string $slug, array $release): ?ReleaseCycle
    {
        $name = $this->cycleName($slug, $release);
        if (null === $name) {
            return null;
        }

        return new ReleaseCycle(
            $name,
            $this->readBool($release, 'isMaintained'),
            $this->readBool($release, 'isEoas'),
            $this->readDate($release, 'eoasFrom'),
            $this->readBool($release, 'isEol'),
            $this->readDate($release, 'eolFrom'),
            $this->latestVersion($slug, $release),
        );
    }

    /**
     * Le nom du cycle s'il est lisible et bien formé, `null` sinon, le motif
     * journalisé.
     *
     * @param array<mixed> $release
     */
    private function cycleName(string $slug, array $release): ?string
    {
        $rawName = $this->readString($release, 'name');
        if (null === $rawName) {
            $this->logger->warning('Cycle de vie ignoré : entrée illisible.', ['slug' => $slug]);

            return null;
        }

        $name = $this->versionNameFilter->keepIfWellFormed($rawName);
        if (null === $name) {
            $this->logger->warning("Cycle de vie ignoré : nom hors de la forme d'un numéro de version.", ['slug' => $slug]);
        }

        return $name;
    }

    /**
     * La dernière version du cycle si elle est bien formée, `null` sinon ;
     * seul un refus est journalisé, une absence est ordinaire.
     *
     * @param array<mixed> $release
     */
    private function latestVersion(string $slug, array $release): ?string
    {
        $latest = $release['latest'] ?? null;
        $rawLatestVersion = \is_array($latest) ? $this->readString($latest, 'name') : null;
        $latestVersion = $this->versionNameFilter->keepIfWellFormed($rawLatestVersion);

        if (null !== $rawLatestVersion && null === $latestVersion) {
            $this->logger->warning("Dernière version ignorée : nom hors de la forme d'un numéro de version.", ['slug' => $slug]);
        }

        return $latestVersion;
    }


    /**
     * Une valeur absente ou d'un autre type vaut `false` : en veille, l'absence
     * d'information ne doit jamais se lire comme « fin de vie atteinte ».
     *
     * @param array<mixed> $data
     */
    private function readBool(array $data, string $key): bool
    {
        return true === ($data[$key] ?? null);
    }

    /**
     * @param array<mixed> $data
     */
    private function readDate(array $data, string $key): ?DateTimeImmutable
    {
        $value = $this->readString($data, $key);
        if (null === $value) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }
}
