<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Version\Version;
use Psr\Log\LogLevel;
use Symfony\Component\ErrorHandler\BufferingLogger;

/**
 * Rejoue une migration déjà appliquée à la base de test, dans la transaction
 * que le test ouvre et annule : `down()` pour retrouver l'état d'avant,
 * `up()` pour la rejouer sur les lignes que le test a écrites, et les
 * `warning` que `up()` journalise.
 *
 * La classe qui l'utilise déclare `MIGRATION` (le nom complet de la
 * migration) et la connexion `$connection` sur laquelle sa transaction est
 * ouverte.
 *
 * @property Connection $connection
 */
trait ReplaysMigration
{
    private function runUp(): void
    {
        $this->runPlanned(static fn (AbstractMigration $migration) => $migration->up(new Schema()));
    }

    private function runDown(): void
    {
        $this->runPlanned(static fn (AbstractMigration $migration) => $migration->down(new Schema()));
    }

    /**
     * Les `warning` que `up()` journalise, le niveau que la sortie du Job de
     * migration affiche sans `-v`. Le SQL planifié n'est pas joué : un test qui
     * vérifie aussi l'effet de `up()` appelle ensuite `runUp()`.
     *
     * @return list<string>
     */
    private function warningsOfUp(): array
    {
        $logger = new BufferingLogger();
        $class = $this->migration()::class;
        new $class($this->connection, $logger)->up(new Schema());

        $messages = [];
        foreach ($logger->cleanLogs() as $log) {
            if (\is_array($log) && LogLevel::WARNING === $log[0] && \is_string($log[1])) {
                $messages[] = $log[1];
            }
        }

        return $messages;
    }

    /**
     * Le dépôt de migrations rend toujours la même instance, dont getSql()
     * cumule : seules les requêtes que `$plan` ajoute sont jouées.
     *
     * @param callable(AbstractMigration): void $plan
     */
    private function runPlanned(callable $plan): void
    {
        $migration = $this->migration();
        $alreadyPlanned = \count($migration->getSql());
        $plan($migration);

        foreach (\array_slice($migration->getSql(), $alreadyPlanned) as $query) {
            $this->connection->executeStatement($query->getStatement());
        }
    }

    private function migration(): AbstractMigration
    {
        $factory = self::getContainer()->get('doctrine.migrations.dependency_factory');

        return $factory->getMigrationRepository()->getMigration(new Version(self::MIGRATION))->getMigration();
    }
}
