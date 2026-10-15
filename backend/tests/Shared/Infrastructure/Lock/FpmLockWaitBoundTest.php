<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Lock;

use App\Security\Authentication\Infrastructure\Http\RateLimiterLockFailureListener;
use App\Tests\Support\PhpImageBuild;
use PHPUnit\Framework\TestCase;

/**
 * Aucune requête web n'attend un verrou indéfiniment (issue #272, audit du
 * correctif, constat M1).
 *
 * Le verrou des limiteurs est un advisory lock PostgreSQL de session :
 * `pg_advisory_lock()` attend sans fin, le TTL du composant Lock ne s'applique
 * pas à ce store, et `max_execution_time` ne compte pas l'attente réseau. Si
 * le détenteur disparaît sans fermer son socket (nœud perdu), PostgreSQL garde
 * le verrou jusqu'à déclarer la session morte — deux heures avant l'issue #279,
 * 90 s depuis (PostgresGhostSessionReleaseTest) — et chaque requête sur la
 * même clé immobilise un worker ; huit suffisent à figer un pod.
 *
 * Le DSN ne permet pas de poser `lock_timeout` sur la seule connexion du verrou
 * (DBAL ne transmet pas `options` à pdo_pgsql, vérifié) : `PGOPTIONS`, lu par
 * libpq, le pose sur toutes les connexions des workers FPM. La console
 * (migrations, worker Messenger, CronJobs) ne lit pas ce pool : elle reçoit la
 * même valeur par l'`env:` de ses pods (issue #525, ConsoleLockWaitBoundTest).
 * `request_terminate_timeout` borne le reste : un worker tué ferme ses
 * connexions, et PostgreSQL annule son attente.
 */
final class FpmLockWaitBoundTest extends TestCase
{
    public function testPostgresLockWaitsOfWebRequestsAreBounded(): void
    {
        self::assertStringContainsString(
            'env[PGOPTIONS] = "-c lock_timeout=5s"',
            PhpImageBuild::productionPool(),
        );
    }

    public function testAWorkerStuckPastTheFastCgiTimeoutIsTerminated(): void
    {
        // Juste au-dessus du fastcgi_read_timeout de 60 s (nginx, ingress) :
        // nginx a déjà répondu 504, le worker n'a plus personne à servir. Au-dessus
        // aussi des 50 s de max_duration de l'assistant en flux.
        self::assertMatchesRegularExpression('/^request_terminate_timeout = 65s$/m', PhpImageBuild::productionPool());
    }

    /**
     * Issue #276 : une panne du verrou répond 503 avec `Retry-After`. Un délai
     * inférieur au `lock_timeout` renverrait le client sur un verrou qui n'a
     * pas encore eu le temps d'échouer une seconde fois — il entretiendrait la
     * saturation qu'il signale. Le lien n'était tenu que par un commentaire.
     */
    public function testTheRetryAfterOfALockFailureOutlastsTheLockTimeout(): void
    {
        self::assertSame(1, preg_match('/lock_timeout=(\d+)s/', PhpImageBuild::productionPool(), $matches));

        self::assertGreaterThan((int) $matches[1], RateLimiterLockFailureListener::RETRY_AFTER_SECONDS);
    }
}
