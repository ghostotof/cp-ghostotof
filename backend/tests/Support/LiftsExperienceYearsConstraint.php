<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Portfolio\Experience\Infrastructure\Doctrine\ExperienceTechnologyRepository;
use Doctrine\DBAL\Connection;

/**
 * Lève la contrainte CHECK de `experience_technology.years` (issue #372) le
 * temps d'un test, pour écrire une ligne que la base refuse désormais — un
 * `NaN`, un `Infinity` d'avant la correction.
 *
 * Tout se passe dans une transaction : PostgreSQL rend le DDL transactionnel,
 * et `restoreExperienceYearsConstraint()`, appelé au tearDown, l'annule — la
 * contrainte revient donc même si le test échoue. Un client HTTP monté avec
 * `disableReboot()` lit sur la même connexion, donc dans cette transaction.
 * Le `DROP` prend un verrou exclusif sur la table jusqu'à l'annulation : sans
 * conséquence tant que la suite tourne sur une seule connexion, à revoir si
 * elle est un jour parallélisée.
 */
trait LiftsExperienceYearsConstraint
{
    private static function liftExperienceYearsConstraint(Connection $connection): void
    {
        $connection->beginTransaction();
        $connection->executeStatement(\sprintf(
            'ALTER TABLE experience_technology DROP CONSTRAINT IF EXISTS %s',
            ExperienceTechnologyRepository::YEARS_CHECK_CONSTRAINT,
        ));
    }

    private static function restoreExperienceYearsConstraint(Connection $connection): void
    {
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
    }
}
