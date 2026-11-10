<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Fixtures;

use Psr\SimpleCache\CacheException;
use RuntimeException;

/**
 * Ce que lève {@see UnreachableSimpleCache} : la panne d'un cache PSR-16 dont
 * le stockage ne répond plus. Son message ne cite jamais la clé demandée —
 * elle porte l'identifiant de session, qui vaut accès à la session. Hors de
 * `src/`, jamais chargée par l'application.
 */
final class UnreachableCacheException extends RuntimeException implements CacheException
{
    public static function storeDown(): self
    {
        return new self('The cache storage did not answer.');
    }
}
