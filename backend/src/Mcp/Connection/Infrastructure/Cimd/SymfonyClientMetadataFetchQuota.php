<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\Cimd;

use App\Mcp\Connection\Application\Cimd\ClientMetadataFetchQuotaInterface;
use App\Mcp\Connection\Domain\Exception\ClientMetadataFetchRateLimitExceededException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Adosse ClientMetadataFetchQuotaInterface au limiteur `mcp_client_metadata`
 * de config/packages/rate_limiter.yaml (fenêtre glissante, 10 par 10 minutes,
 * #651 D-F). Même patron que SymfonyBaseAccessRateLimiter, à ceci près que la
 * clé n'est pas passée par l'appelant : c'est l'adresse IP de la requête
 * principale, lue ici, pour que la couche applicative n'ait pas à connaître
 * HTTP.
 *
 * La clé est celle de {@see FetchQuotaKey}, non l'adresse brute : **une IPv6
 * est comptée par son /64** (qui tient un /64 change d'adresse à volonté et
 * obtiendrait sinon un quota neuf à chaque essai), une IPv4 reste entière, une
 * IPv4 écrite en IPv6 (`::ffff:a.b.c.d`) compte comme cette IPv4. Pour ce
 * limiteur seulement.
 *
 * Hors requête (une commande console, un test sans pile) ou pour une adresse
 * illisible, la clé est `unknown` : tous ces appels partagent alors un seul
 * compteur, la voie sûre (elle limite trop, jamais pas assez).
 *
 * Le décompte vit dans `cache.rate_limiter`, donc en base, sous le verrou
 * partagé entre pods du projet (RateLimiterStorageTest, ADR 0005).
 */
final readonly class SymfonyClientMetadataFetchQuota implements ClientMetadataFetchQuotaInterface
{
    public function __construct(
        #[Autowire(service: 'limiter.mcp_client_metadata')]
        private RateLimiterFactory $rateLimiterFactory,
        private RequestStack $requests,
    ) {
    }

    public function consume(): void
    {
        $key = FetchQuotaKey::forAddress($this->requests->getMainRequest()?->getClientIp());

        $limit = $this->rateLimiterFactory->create($key)->consume();

        if (!$limit->isAccepted()) {
            throw new ClientMetadataFetchRateLimitExceededException($limit->getRetryAfter());
        }
    }
}
