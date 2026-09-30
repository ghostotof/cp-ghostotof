<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Lock;

use PHPUnit\Framework\TestCase;

/**
 * Aucune requête web n'attend un verrou indéfiniment (issue #272, audit du
 * correctif, constat M1).
 *
 * Le verrou des limiteurs est un advisory lock PostgreSQL de session :
 * `pg_advisory_lock()` attend sans fin, le TTL du composant Lock ne s'applique
 * pas à ce store, et `max_execution_time` ne compte pas l'attente réseau. Si
 * le détenteur disparaît sans fermer son socket (nœud perdu), PostgreSQL garde
 * le verrou jusqu'au keepalive TCP — de l'ordre de deux heures — et chaque
 * requête sur la même clé immobilise un worker ; huit suffisent à figer un pod.
 *
 * Le DSN ne permet pas de poser `lock_timeout` sur la seule connexion du verrou
 * (DBAL ne transmet pas `options` à pdo_pgsql, vérifié) : `PGOPTIONS`, lu par
 * libpq, le pose sur toutes les connexions des workers FPM — la console
 * (migrations, worker Messenger, CronJobs) n'est pas concernée.
 * `request_terminate_timeout` borne le reste : un worker tué ferme ses
 * connexions, et PostgreSQL annule son attente.
 */
final class FpmLockWaitBoundTest extends TestCase
{
    public function testPostgresLockWaitsOfWebRequestsAreBounded(): void
    {
        self::assertStringContainsString(
            'env[PGOPTIONS] = "-c lock_timeout=5s"',
            $this->productionPool(),
        );
    }

    public function testAWorkerStuckPastTheFastCgiTimeoutIsTerminated(): void
    {
        // Juste au-dessus du fastcgi_read_timeout de 60 s (nginx, ingress) :
        // nginx a déjà répondu 504, le worker n'a plus personne à servir. Au-dessus
        // aussi des 50 s de max_duration de l'assistant en flux.
        self::assertMatchesRegularExpression('/^request_terminate_timeout = 65s$/m', $this->productionPool());
    }

    private function productionPool(): string
    {
        $path = \dirname(__DIR__, 5).'/docker/php/www.prod.conf';
        self::assertFileIsReadable($path);

        return (string) file_get_contents($path);
    }
}
