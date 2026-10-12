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
use Symfony\Component\Uid\Uuid;

/**
 * #390 : avant la garde, un groupe de traduction pouvait porter deux positions
 * (une écriture SQL, une migration, la course de #389). Poser les triggers sur
 * une base qui en contient un ne suffit pas : la première écriture qui
 * toucherait ce groupe serait refusée. La migration les aligne donc d'abord,
 * et signale chacun.
 *
 * La base de test est migrée avant la suite : les triggers y sont déjà posés.
 * Chaque test tourne dans une transaction annulée au tearDown : il rejoue
 * `down()` pour retrouver l'état d'avant #390, écrit les groupes divergents,
 * puis rejoue `up()`. PostgreSQL rend le DDL transactionnel, et un trigger
 * différé ne se déclenche jamais dans une transaction annulée : les triggers
 * reviennent d'eux-mêmes, même si le test échoue.
 */
final class Version20261012120000Test extends KernelTestCase
{
    private const string MIGRATION = 'DoctrineMigrations\Version20261012120000';

    private const string TRIGGER = 'trg_translation_group_single_position';

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $this->connection->beginTransaction();
        $this->connection->executeStatement('DELETE FROM contribution');
        $this->runPlanned(static fn (AbstractMigration $migration) => $migration->down(new Schema()));
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    /**
     * Un groupe divergent prend la plus petite de ses positions ; un groupe
     * cohérent et une entrée seule ne bougent pas.
     */
    public function testDivergentGroupsAreAlignedOnTheirLowestPosition(): void
    {
        $divergent = Uuid::v7();
        $coherent = Uuid::v7();
        $alone = Uuid::v7();
        $this->insertContribution($divergent, 'fr', 5);
        $this->insertContribution($divergent, 'en', 3);
        $this->insertContribution($coherent, 'fr', 1);
        $this->insertContribution($coherent, 'en', 1);
        $this->insertContribution($alone, 'fr', 8);

        $this->runUp();

        self::assertSame([3, 3], $this->positionsOf($divergent));
        self::assertSame([1, 1], $this->positionsOf($coherent));
        self::assertSame([8], $this->positionsOf($alone));
    }

    /**
     * Les positions d'origine ne sont conservées nulle part ailleurs : chaque
     * groupe réaligné est signalé en `warning`, le niveau que la sortie du Job
     * de migration affiche sans `-v`, avec sa table, son groupe et ses
     * positions par langue.
     */
    public function testEveryAlignedGroupIsReportedWithItsOriginalPositions(): void
    {
        $divergent = Uuid::v7();
        $this->insertContribution($divergent, 'fr', 5);
        $this->insertContribution($divergent, 'en', 3);
        $coherent = Uuid::v7();
        $this->insertContribution($coherent, 'fr', 1);
        $this->insertContribution($coherent, 'en', 1);

        $logger = new BufferingLogger();
        $class = $this->migration()::class;
        new $class($this->connection, $logger)->up(new Schema());

        $warnings = array_values(array_filter(
            $logger->cleanLogs(),
            static fn (mixed $log): bool => \is_array($log) && LogLevel::WARNING === $log[0],
        ));
        self::assertCount(1, $warnings, 'Seul le groupe divergent est signalé.');
        $message = $warnings[0][1];
        self::assertIsString($message);
        self::assertStringContainsString('contribution', $message);
        self::assertStringContainsString($divergent->toRfc4122(), $message);
        self::assertStringContainsString('en 3, fr 5', $message);
    }

    /** Après `up()`, chacune des huit tables porte son trigger différé. */
    public function testUpInstallsTheDeferredTriggerOnEightTables(): void
    {
        self::assertSame(0, $this->deferredTriggerCount(), 'Prémisse : down() a retiré les triggers.');

        $this->runUp();

        self::assertSame(8, $this->deferredTriggerCount());
    }

    /**
     * `down()` retire les triggers **et** la fonction : sans quoi un `up()`
     * rejoué échouerait sur `CREATE FUNCTION`.
     */
    public function testDownRemovesTheTriggersAndTheFunction(): void
    {
        self::assertSame(0, $this->deferredTriggerCount());
        self::assertNull($this->connection->fetchOne("SELECT to_regproc('portfolio_translation_group_single_position')"));
    }

    private function runUp(): void
    {
        $this->runPlanned(static fn (AbstractMigration $migration) => $migration->up(new Schema()));
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

    private function insertContribution(Uuid $group, string $locale, int $position): void
    {
        $this->connection->insert('contribution', [
            'id' => Uuid::v7()->toRfc4122(),
            'translation_group' => $group->toRfc4122(),
            'locale' => $locale,
            'position' => $position,
            'title' => 'T',
            'project' => 'P',
            'reference' => 'R',
            'url' => 'https://example.com',
            'summary' => 'S',
            'body' => 'B',
        ]);
    }

    /**
     * @return list<int>
     */
    private function positionsOf(Uuid $group): array
    {
        /** @var list<int> $positions */
        $positions = $this->connection->fetchFirstColumn(
            'SELECT position FROM contribution WHERE translation_group = ? ORDER BY locale',
            [$group->toRfc4122()],
        );

        return $positions;
    }

    private function deferredTriggerCount(): int
    {
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM pg_trigger WHERE tgname = ? AND tgdeferrable AND tginitdeferred',
            [self::TRIGGER],
        );
        self::assertIsInt($count);

        return $count;
    }
}
