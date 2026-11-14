<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Infrastructure\Cimd;

use App\Mcp\Connection\Domain\ValueObject\CimdClientId;
use App\Mcp\Connection\Infrastructure\Cimd\CachedClientMetadataStore;
use App\Tests\Mcp\Connection\Fixtures\ClaudeCodeMetadata;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le magasin des documents de clients CIMD (#651, D-G) : le pool DBAL
 * `cache.mcp_client_metadata`, valeurs authentifiées, clé dérivée du
 * `client_id` par SHA-256 (l'URL, choisie par un tiers, n'est jamais une clé
 * ni une partie de clé).
 */
final class CachedClientMetadataStoreTest extends KernelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->pool()->clear();
    }

    public function testASavedBodyIsFoundAgain(): void
    {
        $store = $this->store();
        $clientId = $this->clientId(ClaudeCodeMetadata::CLIENT_ID);

        self::assertNull($store->find($clientId), 'Un magasin vide ne répond rien.');

        $store->save($clientId, ClaudeCodeMetadata::json(), 300);

        self::assertSame(ClaudeCodeMetadata::json(), $store->find($clientId));
    }

    public function testAnotherClientIdIsNotFound(): void
    {
        $store = $this->store();
        $store->save($this->clientId(ClaudeCodeMetadata::CLIENT_ID), ClaudeCodeMetadata::json(), 300);

        self::assertNull($store->find($this->clientId('https://claude.ai/oauth/another-client-metadata')));
    }

    public function testNoKeyOfTheTableCarriesTheClientUrl(): void
    {
        $this->store()->save($this->clientId(ClaudeCodeMetadata::CLIENT_ID), ClaudeCodeMetadata::json(), 300);

        $connection = self::getContainer()->get(Connection::class);

        /** @var list<string> $keys */
        $keys = $connection->fetchFirstColumn('SELECT item_id FROM cache_items');

        self::assertNotSame([], $keys);
        foreach ($keys as $key) {
            self::assertStringNotContainsString('claude.ai', $key, 'La clé est une empreinte, jamais l\'URL du client_id.');
        }
    }

    public function testAnExpiredBodyIsNotFound(): void
    {
        $store = $this->store();
        $clientId = $this->clientId(ClaudeCodeMetadata::CLIENT_ID);

        // Une durée nulle ou négative n'est jamais gardée : `expiresAfter()` du PSR-6.
        $store->save($clientId, ClaudeCodeMetadata::json(), -1);

        self::assertNull($store->find($clientId));
    }

    private function store(): CachedClientMetadataStore
    {
        return new CachedClientMetadataStore($this->pool());
    }

    private function pool(): CacheItemPoolInterface
    {
        return self::getContainer()->get('cache.mcp_client_metadata');
    }

    private function clientId(string $url): CimdClientId
    {
        $clientId = CimdClientId::tryFrom($url);
        self::assertNotNull($clientId);

        return $clientId;
    }
}
