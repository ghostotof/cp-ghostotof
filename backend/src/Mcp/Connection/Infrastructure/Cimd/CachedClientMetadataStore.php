<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\Cimd;

use App\Mcp\Connection\Application\Cimd\ClientMetadataStoreInterface;
use App\Mcp\Connection\Domain\ValueObject\CimdClientId;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Le magasin des documents CIMD (#651, D-G) : le pool `cache.mcp_client_metadata`
 * de cache.yaml, sur la table `cache_items` (ADR 0005 : en base, partagé entre
 * les pods), valeurs authentifiées comme tout pool DBAL du projet
 * (CacheItemsAreAuthenticatedTest, `backend-integrite.md`).
 *
 * La clé est le SHA-256 du `client_id` : l'URL est choisie par un tiers, elle
 * peut être longue ou porter des caractères réservés aux clés PSR-6, et elle
 * ne doit jamais se lire dans la table. La valeur est le corps brut du
 * document, une chaîne : aucun objet n'est sérialisé, donc aucun objet n'est
 * désérialisé d'un cache, même authentifié.
 */
final readonly class CachedClientMetadataStore implements ClientMetadataStoreInterface
{
    public function __construct(
        #[Autowire(service: 'cache.mcp_client_metadata')]
        private CacheItemPoolInterface $pool,
    ) {
    }

    public function find(CimdClientId $clientId): ?string
    {
        $item = $this->pool->getItem($this->keyOf($clientId));
        if (!$item->isHit()) {
            return null;
        }

        $body = $item->get();

        // Une valeur qui n'est pas du texte n'est pas ce que `save()` a écrit : un manque.
        return \is_string($body) ? $body : null;
    }

    public function save(CimdClientId $clientId, string $body, int $ttl): void
    {
        $item = $this->pool->getItem($this->keyOf($clientId));
        $item->set($body);
        $item->expiresAfter($ttl);

        $this->pool->save($item);
    }

    private function keyOf(CimdClientId $clientId): string
    {
        return hash('sha256', $clientId->value());
    }
}
