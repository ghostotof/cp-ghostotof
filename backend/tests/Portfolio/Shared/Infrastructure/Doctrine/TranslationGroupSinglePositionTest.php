<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Shared\Infrastructure\Doctrine;

use App\Portfolio\About\Application\AboutMeCardAdministratorInterface;
use App\Portfolio\About\Domain\Entity\AboutMeCard;
use App\Portfolio\About\Domain\ValueObject\AboutMeCardCategory;
use App\Portfolio\Shared\Domain\TranslatableContent;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Portfolio\Shared\Infrastructure\Doctrine\TranslationGroupRefusal;
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

    /**
     * Les tables dont l'ordre se découpe en périmètres, avec la colonne qui les
     * découpe (issue #501) : un groupe ne doit pas s'y répartir sur deux
     * périmètres. Une table absente d'ici n'en forme qu'un. Une nouvelle table
     * ordonnée par périmètre s'ajoute ici, et à la migration qui lui pose le
     * trigger avec cette colonne en argument.
     */
    private const array SCOPE_COLUMNS = ['about_me_card' => 'category'];

    /** Ce que le trigger surveille sur toute table, en plus de la colonne de périmètre. */
    private const array WATCHED_COLUMNS = ['position', 'translation_group'];

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
     * Issue #501 : les cartes « moi » s'ordonnent par catégorie. La version EN
     * insérée en SQL dans une autre catégorie, à la même position que la FR,
     * passait la garde des positions : le groupe recevait deux rangs, un par
     * catégorie.
     */
    public function testInsertingTheOtherVersionOfAMeCardInAnotherCategoryIsRefused(): void
    {
        self::bootKernel();
        $this->table = $table = 'about_me_card';
        $french = $this->meCards()->create(Locale::FR, AboutMeCardCategory::HOBBY, 'T', 'D', null);

        $columns = $this->columnsOf($table);
        $copied = array_map(static fn (string $column): string => match ($column) {
            'id' => 'gen_random_uuid()',
            'locale' => "'".Locale::EN->value."'",
            'category' => "'".AboutMeCardCategory::TECHNICAL->value."'",
            default => $column,
        }, $columns);

        $this->assertRefused(
            \sprintf('INSERT INTO %1$s (%2$s) SELECT %3$s FROM %1$s WHERE id = ?', $table, implode(', ', $columns), implode(', ', $copied)),
            [$french->getId()->toRfc4122()],
            $french->getTranslationGroup(),
            TranslationGroupRefusal::ACROSS_SEVERAL_SCOPES,
        );
    }

    /**
     * Le défaut type de #501 : un membre changé de catégorie seul, sans toucher
     * ni sa position ni son groupe — une colonne que le trigger n'écoutait pas.
     */
    public function testMovingOneMeCardToAnotherCategoryIsRefused(): void
    {
        [$french, $english] = $this->meCardPair();

        $this->assertRefused(
            'UPDATE about_me_card SET category = ? WHERE id = ?',
            [AboutMeCardCategory::TECHNICAL->value, $english->getId()->toRfc4122()],
            $french->getTranslationGroup(),
            TranslationGroupRefusal::ACROSS_SEVERAL_SCOPES,
        );
    }

    /**
     * Un groupe à la fois réparti et sur deux positions sort sous le nom de la
     * cause : la catégorie est contrôlée avant la position, qui n'en est que la
     * conséquence (chaque catégorie numérote ses propres rangs).
     */
    public function testAMeCardGroupAcrossCategoriesAndPositionsIsRefusedForItsCategories(): void
    {
        [$french, $english] = $this->meCardPair();

        $this->assertRefused(
            'UPDATE about_me_card SET category = ?, position = position + 7 WHERE id = ?',
            [AboutMeCardCategory::TECHNICAL->value, $english->getId()->toRfc4122()],
            $french->getTranslationGroup(),
            TranslationGroupRefusal::ACROSS_SEVERAL_SCOPES,
        );
    }

    /**
     * Le contrôle reste différé : déplacer le groupe entier d'une catégorie à
     * l'autre, membre par membre dans une transaction, est accepté.
     */
    public function testMovingAWholeMeCardGroupToAnotherCategoryIsAccepted(): void
    {
        [$french, $english] = $this->meCardPair();
        $probe = $this->probe();

        try {
            $probe->beginTransaction();
            foreach ([$french, $english] as $member) {
                $probe->executeStatement('UPDATE about_me_card SET category = ? WHERE id = ?', [AboutMeCardCategory::TECHNICAL->value, $member->getId()->toRfc4122()]);
            }
            $probe->commit();
        } finally {
            $probe->close();
        }

        self::assertSame(
            [AboutMeCardCategory::TECHNICAL->value],
            $this->connection()->fetchFirstColumn('SELECT DISTINCT category FROM about_me_card WHERE translation_group = ?', [$french->getTranslationGroup()->toRfc4122()]),
            'Le déplacement du groupe entier n\'a pas été validé.',
        );
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

        // tgattr : les colonnes d'`UPDATE OF` ; tgargs : les arguments passés à
        // la fonction, chacun terminé par un octet nul.
        $triggers = $entityManager->getConnection()->fetchAllAssociative(
            'SELECT c.relname AS "table", encode(t.tgargs, \'escape\') AS arguments,'
            .' ARRAY_TO_STRING(ARRAY(SELECT a.attname FROM pg_attribute a WHERE a.attrelid = t.tgrelid AND a.attnum = ANY (t.tgattr::int2[]) ORDER BY a.attname), \',\') AS watched'
            .' FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid'
            .' WHERE t.tgname = ? AND t.tgdeferrable AND t.tginitdeferred AND t.tgenabled = ? ORDER BY c.relname',
            [self::TRIGGER, 'O'],
        );

        self::assertSame($tables, array_column($triggers, 'table'), 'Une table localisée n\'a pas son trigger différé (#390).');

        foreach ($triggers as $trigger) {
            \assert(\is_string($trigger['table']) && \is_string($trigger['arguments']) && \is_string($trigger['watched']));
            $scopeColumn = self::SCOPE_COLUMNS[$trigger['table']] ?? null;
            $watched = null === $scopeColumn ? self::WATCHED_COLUMNS : [...self::WATCHED_COLUMNS, $scopeColumn];
            sort($watched);

            // Issue #501 : la colonne de périmètre est l'argument de la fonction,
            // et une écriture de cette seule colonne réveille le trigger.
            self::assertSame(
                null === $scopeColumn ? [] : [$scopeColumn],
                array_values(array_filter(explode('\\000', $trigger['arguments']), static fn (string $argument): bool => '' !== $argument)),
                \sprintf('Le trigger de %s ne reçoit pas sa colonne de périmètre (#501).', $trigger['table']),
            );
            self::assertSame(implode(',', $watched), $trigger['watched'], \sprintf('Le trigger de %s n\'écoute pas les bonnes colonnes (#501).', $trigger['table']));
        }
    }

    /**
     * Une carte « moi » FR et sa version EN, dans le même groupe et la même
     * catégorie, créées par l'administrateur.
     *
     * @return array{AboutMeCard, AboutMeCard}
     */
    private function meCardPair(): array
    {
        self::bootKernel();
        $this->table = 'about_me_card';
        $french = $this->meCards()->create(Locale::FR, AboutMeCardCategory::HOBBY, 'T', 'D', null);

        return [$french, $this->meCards()->create(Locale::EN, AboutMeCardCategory::HOBBY, 'T', 'D', null, $french->getTranslationGroup())];
    }

    private function meCards(): AboutMeCardAdministratorInterface
    {
        return self::getContainer()->get(AboutMeCardAdministratorInterface::class);
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
     * refus au COMMIT, sous `$sqlState`, nommant le groupe ; la base n'en
     * garde rien.
     *
     * @param list<int|string> $parameters
     */
    private function assertRefused(string $sql, array $parameters, Uuid $group, string $sqlState = TranslationGroupRefusal::ON_SEVERAL_POSITIONS): void
    {
        $before = $this->rowsOf($group);
        $probe = $this->probe();

        try {
            $probe->beginTransaction();
            $probe->executeStatement($sql, $parameters);
            $probe->commit();
            self::fail(\sprintf('La base a validé l\'écriture qu\'elle devait refuser en %s (#390, #501).', $sqlState));
        } catch (DriverException $exception) {
            self::assertSame($sqlState, $exception->getSQLState(), $exception->getMessage());
            self::assertStringContainsString($group->toRfc4122(), $exception->getMessage(), 'Le refus ne nomme pas le groupe fautif.');
        } finally {
            $probe->close();
        }

        self::assertSame($before, $this->rowsOf($group), 'La transaction refusée a laissé une trace en base.');
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
     * Les lignes du groupe, toutes colonnes, lues en base : une écriture
     * refusée ne doit en avoir changé aucune (position, catégorie…).
     *
     * @return list<array<string, mixed>>
     */
    private function rowsOf(Uuid $group): array
    {
        return $this->connection()->fetchAllAssociative(
            \sprintf('SELECT * FROM %s WHERE translation_group = ? ORDER BY locale', $this->table),
            [$group->toRfc4122()],
        );
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
