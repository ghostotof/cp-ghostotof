<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;

/**
 * Fait refuser le COMMIT de la transaction en cours par PostgreSQL, sous un
 * SQLSTATE que DBAL ne range dans aucune de ses classes (issue #512).
 *
 * Une table temporaire à contrainte d'exclusion **différée** reçoit deux
 * lignes égales : chaque instruction passe, le COMMIT échoue en `23P01`
 * (`exclusion_violation`), une simple `DriverException` pour DBAL. C'est
 * précisément le cas que `Connection::transactional()` masquait derrière un
 * « There is no active transaction » : `23P01` est hors de la courte liste
 * des refus après lesquels il renonce à son `rollBack()` de repli. La table,
 * créée dans la transaction, disparaît avec son annulation.
 *
 * Contrainte d'exclusion plutôt qu'un trigger différé : une seule
 * instruction, et `btree` suffit pour l'égalité, sans extension.
 */
final class CommitRefusal
{
    /** SQLSTATE `exclusion_violation`, que le COMMIT refusé porte. */
    public const string SQLSTATE = '23P01';

    /**
     * À appeler **dans** la transaction dont le COMMIT doit échouer.
     */
    public static function scheduleIn(Connection $connection): void
    {
        $connection->executeStatement(
            'CREATE TEMPORARY TABLE commit_refusal (k int, EXCLUDE USING btree (k WITH =) DEFERRABLE INITIALLY DEFERRED) ON COMMIT DROP',
        );
        $connection->executeStatement('INSERT INTO commit_refusal (k) VALUES (1), (1)');
    }
}
