<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Version\Version;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LogLevel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\ErrorHandler\BufferingLogger;

/**
 * Régression #372 : avant la correction, `{"years":1e999}` au backoffice ou
 * `--years=1e999` en CLI persistaient `Infinity`, et une ligne non encodable
 * mettait GET /api/experience/technologies en 500 pour tout le monde.
 *
 * La base de test est migrée avant la suite : la contrainte y est donc déjà
 * posée. Chaque test la lève dans une transaction pour reconstruire l'état
 * d'avant #372, rejoue le SQL de `up()`, puis la transaction est annulée au
 * tearDown — PostgreSQL rend le DDL transactionnel, la contrainte revient
 * d'elle-même, même si le test échoue.
 */
final class Version20261006120000Test extends KernelTestCase
{
    private const string MIGRATION = 'DoctrineMigrations\Version20261006120000';

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $this->connection->beginTransaction();
        $this->connection->executeStatement('DELETE FROM experience_technology');
        $this->connection->executeStatement(
            'ALTER TABLE experience_technology DROP CONSTRAINT IF EXISTS chk_experience_technology_years',
        );
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    /**
     * Une durée inventée ne doit jamais s'afficher sur le site : la ligne est
     * ramenée dans les bornes **et** rangée parmi les technologies secondaires,
     * dont la page n'affiche pas la durée (l'API publique, elle, porte toujours
     * `years`). Elle reste visible, corrigeable au backoffice d'un clic.
     */
    public function testOutOfRangeRowsAreClampedAndMovedToSecondary(): void
    {
        $this->insertTechnology('Infini', "'Infinity'::float8");
        $this->insertTechnology('Moins-infini', "'-Infinity'::float8");
        $this->insertTechnology('NaN', "'NaN'::float8");
        $this->insertTechnology('Négatif', '-3');
        $this->insertTechnology('Trop', '120');

        $this->runUp();

        self::assertSame(['years' => 100.0, 'secondary' => true], $this->row('Infini'));
        self::assertSame(['years' => 0.0, 'secondary' => true], $this->row('Moins-infini'));
        self::assertSame(['years' => 0.0, 'secondary' => true], $this->row('NaN'));
        self::assertSame(['years' => 0.0, 'secondary' => true], $this->row('Négatif'));
        self::assertSame(['years' => 100.0, 'secondary' => true], $this->row('Trop'));
    }

    /**
     * La valeur d'origine n'est conservée nulle part ailleurs : chaque ligne
     * touchée est signalée en `warning` — le niveau que la sortie console du
     * Job de migration affiche sans `-v` — avec son nom et sa valeur, pour
     * qu'un administrateur sache quoi corriger au backoffice.
     */
    public function testEveryRewrittenRowIsReportedWithItsOriginalValue(): void
    {
        $this->insertTechnology('Infini', "'Infinity'::float8");
        $this->insertTechnology('Trop', '120');
        $this->insertTechnology('PHP', '13.5');

        $logger = new BufferingLogger();
        $class = $this->migration()::class;
        new $class($this->connection, $logger)->up(new Schema());

        $warnings = array_values(array_filter(
            $logger->cleanLogs(),
            static fn (array $log): bool => LogLevel::WARNING === $log[0],
        ));
        $messages = array_column($warnings, 1);
        sort($messages);
        self::assertCount(2, $messages);
        self::assertStringContainsString('"Infini"', $messages[0]);
        self::assertStringContainsString('Infinity', $messages[0]);
        self::assertStringContainsString('"Trop"', $messages[1]);
        self::assertStringContainsString('120', $messages[1]);
    }

    /** Seules les lignes fautives changent, bornes comprises. */
    public function testRowsWithinBoundsAreLeftAlone(): void
    {
        $this->insertTechnology('PHP', '13.5');
        $this->insertTechnology('Rust', '0');
        $this->insertTechnology('Borne', '100');

        $this->runUp();

        self::assertSame(['years' => 13.5, 'secondary' => false], $this->row('PHP'));
        self::assertSame(['years' => 0.0, 'secondary' => false], $this->row('Rust'));
        self::assertSame(['years' => 100.0, 'secondary' => false], $this->row('Borne'));
    }

    /** Après `up()`, la contrainte est en place : `Infinity` ne repasse plus. */
    public function testTheConstraintIsInPlaceAfterUp(): void
    {
        $this->runUp();

        self::assertSame(1, $this->constraintCount());
    }

    /**
     * `down()` retire la contrainte ; il ne rend pas les valeurs bornées, que
     * personne ne veut voir revenir (elles mettaient la route publique en 500).
     */
    public function testDownRemovesTheConstraint(): void
    {
        $this->runUp();

        // Le dépôt de migrations rend toujours la même instance, dont
        // getSql() cumule : seules les requêtes ajoutées par down() comptent.
        $migration = $this->migration();
        $alreadyPlanned = \count($migration->getSql());
        $migration->down(new Schema());
        foreach (\array_slice($migration->getSql(), $alreadyPlanned) as $query) {
            $this->connection->executeStatement($query->getStatement());
        }

        self::assertSame(0, $this->constraintCount());
    }

    private function runUp(): void
    {
        $migration = $this->migration();
        $migration->up(new Schema());

        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement());
        }
    }

    private function migration(): AbstractMigration
    {
        $factory = self::getContainer()->get('doctrine.migrations.dependency_factory');

        return $factory->getMigrationRepository()->getMigration(new Version(self::MIGRATION))->getMigration();
    }

    private function insertTechnology(string $name, string $yearsLiteral): void
    {
        $this->connection->executeStatement(
            sprintf('INSERT INTO experience_technology (id, name, years) VALUES (uuidv7(), ?, %s)', $yearsLiteral),
            [$name],
        );
    }

    /**
     * @return array{years: float, secondary: bool}
     */
    private function row(string $name): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT years, secondary FROM experience_technology WHERE name = ?',
            [$name],
        );
        self::assertIsArray($row);
        self::assertIsNumeric($row['years']);

        return ['years' => (float) $row['years'], 'secondary' => (bool) $row['secondary']];
    }

    private function constraintCount(): int
    {
        $count = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM pg_constraint WHERE conname = 'chk_experience_technology_years' AND contype = 'c'",
        );
        self::assertIsInt($count);

        return $count;
    }
}
