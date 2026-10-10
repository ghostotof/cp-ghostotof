<?php

declare(strict_types=1);

namespace App\Tests\Support;

use RuntimeException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;

/**
 * Store de verrou qui simule la panne du verrou des limiteurs (issue #276) :
 * seconde connexion PostgreSQL refusée, `max_connections` atteint ou
 * `lock_timeout` dépassé. `save()` lève une exception quelconque, que
 * `Lock::acquire()` enveloppe dans une `LockAcquiringException` — exactement
 * ce que produit le vrai `DoctrineDbalPostgreSqlStore` dans ces trois cas.
 */
final class UnavailableLockStore implements PersistingStoreInterface
{
    public function save(Key $key): void
    {
        throw new RuntimeException('SQLSTATE[08006] connection to server failed (simulé).');
    }

    public function delete(Key $key): void
    {
    }

    public function exists(Key $key): bool
    {
        return false;
    }

    public function putOffExpiration(Key $key, float $ttl): void
    {
    }
}
