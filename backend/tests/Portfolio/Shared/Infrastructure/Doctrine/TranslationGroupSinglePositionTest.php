<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Shared\Infrastructure\Doctrine;

use App\Portfolio\Shared\Domain\TranslatableContent;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Portfolio\Shared\Infrastructure\Doctrine\PostgresAdvisoryOrderScopeLock;
use App\Tests\Portfolio\Shared\Application\ProvidesLocalizedAdministrators;
use App\Tests\Support\OpensProbeConnection;
use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Issue #390 : toutes les entrées d'un groupe de traduction partagent sa
 * position (spec 0004 D3), et c'est la base qui le garantit.
 *
 * `ContentPlacement::inGroup()` ne voyait un groupe sur deux positions qu'au
 * rattachement d'une nouvelle langue, ce que le backoffice ne propose presque
 * jamais. La lecture publique rangeait alors le contenu à des rangs différents
 * en FR et en EN, et le réordonnancement suivant réparait l'écart sans laisser
 * de trace. Un trigger de contrainte différé refuse désormais, au COMMIT, toute
 * transaction qui laisse un groupe sur plusieurs positions, quel que soit le
 * chemin d'écriture : l'application, une migration ou une requête SQL.
 *
 * Chaque écriture fautive passe par une **seconde session** (la sonde), dans sa
 * propre transaction : le refus tombe au COMMIT, et rien ne rejaillit sur la
 * connexion de l'ORM. Ce que l'application reçoit d'un tel refus est épinglé
 * par PostgresAdvisoryOrderScopeLockTest.
 *
 * @phpstan-import-type LocalizedContext from ProvidesLocalizedAdministrators
 */
final class TranslationGroupSinglePositionTest extends KernelTestCase
{
    use OpensProbeConnection;
    use ProvidesLocalizedAdministrators;

    /** Nom du trigger, le même sur chaque table (un nom de trigger est propre à sa table). */
    private const string TRIGGER = 'trg_translation_group_single_position';

    private ?string $table = null;

    protected function tearDown(): void
    {
        if (null !== $this->table) {
            $this->connection()->executeStatement('DELETE FROM '.$this->table);
        }
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{0: class-string<TranslatableContent>, 1: Closure(): LocalizedContext}>
     */
    public static function provideLocalizedContexts(): iterable
    {
        yield from self::localizedAdministrators();
    }

    /**
     * Le défaut type : un membre déplacé seul, par une écriture SQL qui ignore
     * le groupe.
     *
     * @param class-string<TranslatableContent> $entityClass
     * @param Closure(): LocalizedContext       $context
     */
    #[DataProvider('provideLocalizedContexts')]
    public function testMovingOneMemberAwayFromItsGroupIsRefused(string $entityClass, Closure $context): void
    {
        [, $french, $english] = $this->pair($entityClass, $context);

        $this->assertRefused(
            \sprintf('UPDATE %s SET position = position + 7 WHERE id = ?', $this->table),
            [$this->idOf($entityClass, $english)],
            $french->getTranslationGroup(),
        );
    }

    /**
     * Rattacher en SQL une entrée sans reprendre la position du groupe : ce que
     * `reattach()` fait toujours, et qu'une écriture directe peut oublier.
     *
     * @param class-string<TranslatableContent> $entityClass
     * @param Closure(): LocalizedContext       $context
     */
    #[DataProvider('provideLocalizedContexts')]
    public function testAttachingAnEntryWithoutTheGroupPositionIsRefused(string $entityClass, Closure $context): void
    {
        self::bootKernel();
        $this->table = $this->tableOf($entityClass);
        $operations = $context();
        $french = $operations['create'](Locale::FR, null);
        $english = $operations['create'](Locale::EN, null);
        self::assertNotSame($french->getPosition(), $english->getPosition(), 'Prémisse : deux entrées sans groupe se rangent à deux positions.');

        $this->assertRefused(
            \sprintf('UPDATE %s SET translation_group = ? WHERE id = ?', $this->table),
            [$french->getTranslationGroup()->toRfc4122(), $this->idOf($entityClass, $english)],
            $french->getTranslationGroup(),
        );
    }

    /**
     * Une version insérée dans un groupe à une autre position que la sienne :
     * le trigger vise l'INSERT autant que l'UPDATE.
     *
     * @param class-string<TranslatableContent> $entityClass
     * @param Closure(): LocalizedContext       $context
     */
    #[DataProvider('provideLocalizedContexts')]
    public function testInsertingAMemberAtAnotherPositionIsRefused(string $entityClass, Closure $context): void
    {
        self::bootKernel();
        $this->table = $table = $this->tableOf($entityClass);
        $french = $context()['create'](Locale::FR, null);

        // La version EN est la copie de la FR, à la position suivante : la
        // liste des colonnes est lue en base, pour valoir sur les huit tables.
        $columns = $this->columnsOf($table);
        $copied = array_map(static fn (string $column): string => match ($column) {
            'id' => 'gen_random_uuid()',
            'locale' => "'".Locale::EN->value."'",
            'position' => 'position + 1',
            default => $column,
        }, $columns);

        $this->assertRefused(
            \sprintf('INSERT INTO %1$s (%2$s) SELECT %3$s FROM %1$s WHERE id = ?', $table, implode(', ', $columns), implode(', ', $copied)),
            [$this->idOf($entityClass, $french)],
            $french->getTranslationGroup(),
        );
    }

    /**
     * Le contrôle est différé au COMMIT : une renumérotation écrit les membres
     * d'un groupe un par un, et l'écart transitoire entre deux `UPDATE` ne doit
     * pas être refusé.
     *
     * @param class-string<TranslatableContent> $entityClass
     * @param Closure(): LocalizedContext       $context
     */
    #[DataProvider('provideLocalizedContexts')]
    public function testADivergenceResolvedBeforeCommitIsAccepted(string $entityClass, Closure $context): void
    {
        [, $french, $english] = $this->pair($entityClass, $context);
        $probe = $this->probe();

        try {
            $probe->beginTransaction();
            foreach ([$french, $english] as $member) {
                $probe->executeStatement(\sprintf('UPDATE %s SET position = 42 WHERE id = ?', $this->table), [$this->idOf($entityClass, $member)]);
            }
            $probe->commit();
        } finally {
            $probe->close();
        }

        self::assertSame([42, 42], $this->positionsOf($french->getTranslationGroup()), 'La renumérotation du groupe n\'a pas été validée.');
    }

    /**
     * Le chemin réel : `reorder()` intervertit deux groupes FR/EN, Doctrine
     * écrit chaque ligne par son propre `UPDATE`, et l'ensemble doit passer.
     *
     * @param class-string<TranslatableContent> $entityClass
     * @param Closure(): LocalizedContext       $context
     */
    #[DataProvider('provideLocalizedContexts')]
    public function testReorderingGroupsThroughTheAdministratorStillCommits(string $entityClass, Closure $context): void
    {
        [$operations, $first] = $this->pair($entityClass, $context);
        $second = $operations['create'](Locale::FR, null);
        $operations['create'](Locale::EN, $second->getTranslationGroup());

        $operations['reorder']([$second->getTranslationGroup()->toRfc4122(), $first->getTranslationGroup()->toRfc4122()]);

        self::assertSame([0, 0], $this->positionsOf($second->getTranslationGroup()));
        self::assertSame([1, 1], $this->positionsOf($first->getTranslationGroup()));
    }

    /**
     * Toute entité localisée et ordonnée porte la garde, différée : un neuvième
     * contexte créé sans sa migration fait rougir ce test, sans attendre qu'on
     * pense à l'ajouter aux scénarios ci-dessus.
     */
    public function testEveryTranslatableEntityCarriesTheDeferredGuard(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $tables = [];
        foreach ($entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            if (is_subclass_of($metadata->getName(), TranslatableContent::class)) {
                $tables[] = $metadata->getTableName();
            }
        }
        sort($tables);
        self::assertCount(\count(iterator_to_array(self::localizedAdministrators())), $tables, 'Prémisse : une table par contexte localisé.');

        $guarded = $entityManager->getConnection()->fetchFirstColumn(
            'SELECT c.relname FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid'
            .' WHERE t.tgname = ? AND t.tgdeferrable AND t.tginitdeferred AND t.tgenabled = ? ORDER BY c.relname',
            [self::TRIGGER, 'O'],
        );

        self::assertSame($tables, $guarded, 'Une table localisée n\'a pas son trigger différé (#390).');
    }

    /**
     * Une entrée FR et sa version EN, dans le même groupe.
     *
     * @param class-string<TranslatableContent> $entityClass
     * @param Closure(): LocalizedContext       $context
     *
     * @return array{0: LocalizedContext, 1: TranslatableContent, 2: TranslatableContent}
     */
    private function pair(string $entityClass, Closure $context): array
    {
        self::bootKernel();
        $this->table = $this->tableOf($entityClass);
        $operations = $context();
        $french = $operations['create'](Locale::FR, null);
        $english = $operations['create'](Locale::EN, $french->getTranslationGroup());

        return [$operations, $french, $english];
    }

    /**
     * Joue l'écriture dans sa propre transaction sur la sonde, et attend le
     * refus au COMMIT, nommant le groupe ; la base n'en garde rien.
     *
     * @param list<int|string> $parameters
     */
    private function assertRefused(string $sql, array $parameters, Uuid $group): void
    {
        $before = $this->positionsOf($group);
        $probe = $this->probe();

        try {
            $probe->beginTransaction();
            $probe->executeStatement($sql, $parameters);
            $probe->commit();
            self::fail('La base a validé un groupe de traduction sur plusieurs positions (#390).');
        } catch (DriverException $exception) {
            self::assertSame(PostgresAdvisoryOrderScopeLock::TRANSLATION_GROUP_ON_SEVERAL_POSITIONS, $exception->getSQLState(), $exception->getMessage());
            self::assertStringContainsString($group->toRfc4122(), $exception->getMessage(), 'Le refus ne nomme pas le groupe fautif.');
        } finally {
            $probe->close();
        }

        self::assertSame($before, $this->positionsOf($group), 'La transaction refusée a laissé une trace en base.');
    }

    private function probe(): Connection
    {
        return self::openProbeBeside($this->connection());
    }

    /** La connexion de l'ORM, celle des administrateurs. */
    private function connection(): Connection
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    /**
     * Positions des membres du groupe, lues en base.
     *
     * @return list<int>
     */
    private function positionsOf(Uuid $group): array
    {
        /** @var list<int> $positions */
        $positions = $this->connection()->fetchFirstColumn(
            \sprintf('SELECT position FROM %s WHERE translation_group = ? ORDER BY locale', $this->table),
            [$group->toRfc4122()],
        );

        return $positions;
    }

    /**
     * @return list<string>
     */
    private function columnsOf(string $table): array
    {
        /** @var list<string> $columns */
        $columns = $this->connection()->fetchFirstColumn(
            'SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? ORDER BY ordinal_position',
            [$table],
        );

        return $columns;
    }

    /**
     * @param class-string<TranslatableContent> $entityClass
     */
    private function tableOf(string $entityClass): string
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getClassMetadata($entityClass)->getTableName();
    }

    /**
     * L'identifiant de l'entrée, lu par les métadonnées : TranslatableContent
     * n'expose pas `getId()`.
     *
     * @param class-string<TranslatableContent> $entityClass
     */
    private function idOf(string $entityClass, TranslatableContent $entry): string
    {
        $id = self::getContainer()->get(EntityManagerInterface::class)->getClassMetadata($entityClass)->getIdentifierValues($entry)['id'] ?? null;
        self::assertInstanceOf(Uuid::class, $id);

        return $id->toRfc4122();
    }
}
