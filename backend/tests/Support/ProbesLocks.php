<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Les sondes des tests de verrous (issue #560) : depuis une **autre** session
 * — une connexion témoin, {@see OpensProbeConnection} —, dire si un verrou est
 * libre, sans jamais l'attendre ni le garder. La session qui tient un verrou
 * peut toujours le reprendre : seule une autre le voit pris.
 *
 * Hors transaction explicite, chaque sonde ne tient ce qu'elle obtient que le
 * temps de son instruction : elle le rend aussitôt.
 */
trait ProbesLocks
{
    use OpensProbeConnection;

    /**
     * La ligne `$id` de `$table`, tentée par `FOR UPDATE SKIP LOCKED` : une
     * ligne tenue n'est pas rendue.
     */
    private function probeCanLockTheRow(Connection $probe, string $table, Uuid $id): bool
    {
        return 1 === $probe->fetchOne(
            \sprintf('SELECT 1 FROM %s WHERE id = :id FOR UPDATE SKIP LOCKED', $probe->quoteSingleIdentifier($table)),
            ['id' => $id->toRfc4122()],
        );
    }

    /**
     * Un advisory lock de transaction à deux clés `int4`, tenté sans attendre.
     */
    private function probeCanTakeTheAdvisoryLock(Connection $probe, int $namespace, int $key): bool
    {
        return true === $probe->fetchOne('SELECT pg_try_advisory_xact_lock(?, ?)', [$namespace, $key]);
    }

    /**
     * Le même, la seconde clé dérivée d'un nom par `hashtext()` — la forme du
     * verrou de périmètre d'ordre.
     */
    private function probeCanTakeTheNamedAdvisoryLock(Connection $probe, int $namespace, string $name): bool
    {
        return true === $probe->fetchOne('SELECT pg_try_advisory_xact_lock(?, hashtext(?))', [$namespace, $name]);
    }
}
