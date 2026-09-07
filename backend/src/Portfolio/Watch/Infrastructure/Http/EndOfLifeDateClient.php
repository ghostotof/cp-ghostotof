<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Infrastructure\Http;

use App\Portfolio\Watch\Domain\Exception\ReleaseCycleProductNotFoundException;
use App\Portfolio\Watch\Domain\Exception\ReleaseCycleSourceUnavailableException;
use App\Portfolio\Watch\Domain\Service\ReleaseCycleSourceInterface;
use App\Portfolio\Watch\Domain\ValueObject\ProductReleaseCycles;
use App\Portfolio\Watch\Domain\ValueObject\ReleaseCycle;
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
    private const string BASE_URL = 'https://endoflife.date/api/v1/products/';

    /** Inactivité maximale entre deux fragments de réponse, en secondes. */
    private const float IDLE_TIMEOUT_SECONDS = 5.0;

    /** Durée maximale de l'appel complet, en secondes. */
    private const float MAX_DURATION_SECONDS = 10.0;

    /**
     * Le fournisseur demande un User-Agent identifiable. On y met de quoi nous
     * joindre — le dépôt public, jamais le domaine de production.
     */
    private const string USER_AGENT = 'cp-ghostotof (+https://github.com/ghostotof/cp-ghostotof)';

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {
    }

    public function fetchProduct(string $slug): ProductReleaseCycles
    {
        try {
            $response = $this->httpClient->request('GET', self::BASE_URL.rawurlencode($slug).'/', [
                'timeout' => self::IDLE_TIMEOUT_SECONDS,
                'max_duration' => self::MAX_DURATION_SECONDS,
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => self::USER_AGENT,
                ],
            ]);

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

            $cycle = $this->mapCycle($release);
            if (null === $cycle) {
                // Une entrée illisible au milieu d'une réponse valide est
                // ignorée plutôt que fatale : mieux vaut afficher quatre cycles
                // sur cinq qu'une page en erreur.
                $this->logger->warning('Cycle de vie ignoré : entrée illisible.', ['slug' => $slug]);

                continue;
            }

            $cycles[] = $cycle;
        }

        $links = $result['links'] ?? null;

        return new ProductReleaseCycles(
            $slug,
            $this->readString($result, 'label') ?? $slug,
            \is_array($links) ? $this->readString($links, 'html') : null,
            $cycles,
        );
    }

    /**
     * @param array<mixed> $release
     */
    private function mapCycle(array $release): ?ReleaseCycle
    {
        $name = $this->readString($release, 'name');
        if (null === $name) {
            return null;
        }

        $latest = $release['latest'] ?? null;

        return new ReleaseCycle(
            $name,
            $this->readBool($release, 'isMaintained'),
            $this->readBool($release, 'isEoas'),
            $this->readDate($release, 'eoasFrom'),
            $this->readBool($release, 'isEol'),
            $this->readDate($release, 'eolFrom'),
            \is_array($latest) ? $this->readString($latest, 'name') : null,
        );
    }

    /**
     * @param array<mixed> $data
     */
    private function readString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return \is_string($value) && '' !== $value ? $value : null;
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
    private function readDate(array $data, string $key): ?\DateTimeImmutable
    {
        $value = $this->readString($data, $key);
        if (null === $value) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
