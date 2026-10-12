<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Lock;

use Closure;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

/**
 * `%env(pg_advisory:DATABASE_URL)%` : le DSN du verrou des limiteurs de débit,
 * dérivé de l'URL de la base (issue #272).
 *
 * Sans verrou, `RateLimiter::consume()` fait un lire-modifier-écrire non
 * atomique sur `cache.app` : k requêtes simultanées ne décomptaient qu'une
 * unité. Le verrou doit être partagé entre pods — jamais `flock` ni
 * `semaphore` (ADR 0005). Ajouter `+advisory` au schéma fait choisir à
 * `StoreFactory` un `DoctrineDbalPostgreSqlStore` : advisory lock PostgreSQL,
 * bloquant, sans table ni migration.
 *
 * Dériver plutôt que déclarer une variable `LOCK_DSN` : ce serait un second
 * secret portant le mot de passe de la base, à router dans chaque
 * ExternalSecret et à tenir synchronisé à chaque rotation. Ici, le verrou suit
 * `DATABASE_URL` par construction.
 */
final class PostgresAdvisoryLockDsnEnvVarProcessor implements EnvVarProcessorInterface
{
    private const string PREFIX = 'pg_advisory';

    /** Les schémas que `StoreFactory` sait suffixer de `+advisory`. */
    private const array POSTGRES_SCHEMES = ['postgresql', 'postgres', 'pgsql'];

    public function getEnv(string $prefix, string $name, Closure $getEnv): string
    {
        $databaseUrl = $getEnv($name);
        $databaseUrl = \is_string($databaseUrl) ? $databaseUrl : '';

        // Le schéma seul, jamais le reste de l'URL (mot de passe).
        $separator = strpos($databaseUrl, '://');
        $scheme = false === $separator ? '' : substr($databaseUrl, 0, $separator);

        if (!\in_array($scheme, self::POSTGRES_SCHEMES, true)) {
            throw UnsupportedLockDatabaseUrlException::forScheme($scheme);
        }

        return $scheme.'+advisory'.substr($databaseUrl, $separator);
    }

    public static function getProvidedTypes(): array
    {
        return [self::PREFIX => 'string'];
    }
}
