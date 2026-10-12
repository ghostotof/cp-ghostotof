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
 * toucherait ce groupe serait refusée. La migration les réaligne donc
 * d'abord, en renumérotant leur périmètre, et signale chacun.
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
        $this->connection->executeStatement('DELETE FROM about_me_card');
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
     * Un groupe divergent est ramené à une seule position, et son périmètre
     * renuméroté `0…n-1` dans l'ordre des plus petites positions. Aligner sur
     * la plus petite sans renuméroter laissait A et B à égalité sur 2, que les
     * dépôts départagent par l'id de chaque ligne : FR et EN pouvaient les
     * ranger dans deux ordres différents, le défaut même de #390. À égalité,
     * le groupe départage, le même pour toutes les langues.
     */
    public function testAPerimeterWithADivergentGroupIsRenumberedInOneOrderForEveryLocale(): void
    {
        $first = Uuid::fromString('00000000-0000-7000-8000-000000000001');
        $second = Uuid::fromString('00000000-0000-7000-8000-000000000002');
        $last = Uuid::fromString('00000000-0000-7000-8000-000000000003');
        $this->insertContribution($second, 'fr', 2);
        $this->insertContribution($second, 'en', 5);
        $this->insertContribution($first, 'fr', 2);
        $this->insertContribution($first, 'en', 2);
        $this->insertContribution($last, 'fr', 9);

        $this->runUp();

        self::assertSame([0, 0], $this->positionsOf($first));
        self::assertSame([1, 1], $this->positionsOf($second));
        self::assertSame([2], $this->positionsOf($last));
    }

    /**
     * Une table sans groupe divergent n'est pas touchée : ses écarts et ses
     * égalités restent ce qu'ils sont.
     */
    public function testAPerimeterWithoutDivergentGroupIsLeftAlone(): void
    {
        $coherent = Uuid::v7();
        $alone = Uuid::v7();
        $this->insertContribution($coherent, 'fr', 4);
        $this->insertContribution($coherent, 'en', 4);
        $this->insertContribution($alone, 'fr', 8);

        $this->runUp();

        self::assertSame([4, 4], $this->positionsOf($coherent));
        self::assertSame([8], $this->positionsOf($alone));
    }

    /**
     * Les cartes « moi » s'ordonnent par catégorie : chacune est renumérotée
     * à partir de 0, indépendamment des autres.
     */
    public function testAboutMeCardsAreRenumberedPerCategory(): void
    {
        $divergent = Uuid::v7();
        $hobby = Uuid::v7();
        $technical = Uuid::v7();
        $this->insertAboutMeCard($divergent, 'fr', 'hobby', 3);
        $this->insertAboutMeCard($divergent, 'en', 'hobby', 6);
        $this->insertAboutMeCard($hobby, 'fr', 'hobby', 7);
        $this->insertAboutMeCard($technical, 'fr', 'technical', 5);

        $this->runUp();

        self::assertSame([0, 0], $this->positionsOf($divergent, 'about_me_card'));
        self::assertSame([1], $this->positionsOf($hobby, 'about_me_card'));
        self::assertSame([0], $this->positionsOf($technical, 'about_me_card'));
    }

    /**
     * Un groupe à cheval sur deux catégories (impossible par l'application,
     * possible en SQL) ne peut pas être réaligné : chaque catégorie se
     * renumérote seule, et le prochain réordonnancement de l'une serait refusé
     * par le trigger. Il est signalé, pour être corrigé au backoffice.
     */
    public function testAGroupAcrossTwoCategoriesIsReported(): void
    {
        $across = Uuid::v7();
        $this->insertAboutMeCard($across, 'fr', 'hobby', 1);
        $this->insertAboutMeCard($across, 'en', 'technical', 1);

        $messages = $this->warningsOfUp();

        self::assertCount(1, $messages);
        self::assertStringContainsString($across->toRfc4122(), $messages[0]);
        self::assertStringContainsString('hobby, technical', $messages[0]);
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

        $messages = $this->warningsOfUp();

        self::assertCount(1, $messages, 'Seul le groupe divergent est signalé.');
        $message = $messages[0];
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

    /**
     * Les `warning` que `up()` journalise, le niveau que la sortie du Job de
     * migration affiche sans `-v`.
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

    private function insertAboutMeCard(Uuid $group, string $locale, string $category, int $position): void
    {
        $this->connection->insert('about_me_card', [
            'id' => Uuid::v7()->toRfc4122(),
            'translation_group' => $group->toRfc4122(),
            'locale' => $locale,
            'category' => $category,
            'position' => $position,
            'title' => 'T',
            'description' => 'D',
        ]);
    }

    /**
     * @return list<int>
     */
    private function positionsOf(Uuid $group, string $table = 'contribution'): array
    {
        /** @var list<int> $positions */
        $positions = $this->connection->fetchFirstColumn(
            \sprintf('SELECT position FROM %s WHERE translation_group = ? ORDER BY locale', $table),
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
