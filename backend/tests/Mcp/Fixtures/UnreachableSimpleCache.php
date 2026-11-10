<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Fixtures;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

/**
 * Un cache PSR-16 injoignable : chaque méthode lève
 * {@see UnreachableCacheException}, comme un cache dont le stockage ne répond
 * plus. Sous le `Psr16SessionStore` du SDK, il donne le store de sessions en
 * panne — `exists()` lève alors `SessionStoreException`, les autres méthodes
 * avalent l'échec. Hors de `src/`, jamais chargé par l'application.
 */
final class UnreachableSimpleCache implements CacheInterface
{
    public function get(string $key, mixed $default = null): mixed
    {
        throw UnreachableCacheException::storeDown();
    }

    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        throw UnreachableCacheException::storeDown();
    }

    public function delete(string $key): bool
    {
        throw UnreachableCacheException::storeDown();
    }

    public function clear(): bool
    {
        throw UnreachableCacheException::storeDown();
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        throw UnreachableCacheException::storeDown();
    }

    /**
     * @param iterable<mixed> $values
     */
    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
    {
        throw UnreachableCacheException::storeDown();
    }

    public function deleteMultiple(iterable $keys): bool
    {
        throw UnreachableCacheException::storeDown();
    }

    public function has(string $key): bool
    {
        throw UnreachableCacheException::storeDown();
    }
}
