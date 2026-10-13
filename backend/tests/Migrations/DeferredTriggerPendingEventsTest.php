<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Portfolio\Shared\Infrastructure\Doctrine\TranslationGroupRefusal;
use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Issue #505 : ce qu'une migration peut écrire, puis altérer, sur une table
 * qui porte le trigger différé `trg_translation_group_single_position`
 * (#390, #501). Le piège et sa parade sont écrits dans
 * `.claude/rules/portfolio-contenus.md` (« Trap for future migrations ») :
 * ce test en épingle les **faits**, mesurés sur PostgreSQL et sur le vrai
 * schéma, pour qu'une montée de version de PostgreSQL ou un changement du
 * trigger qui les contredirait fasse tomber la règle en même temps.
 *
 * Un trigger différé laisse un événement en attente pour chaque ligne qu'une
 * écriture surveillée touche, jusqu'au COMMIT ; PostgreSQL refuse alors la
 * plupart des `ALTER TABLE` et les `CREATE INDEX` sur cette table, en
 * SQLSTATE `55006` (« pending trigger events »). Ce qui en découle pour une
 * migration (chacune tourne dans sa propre transaction) :
 * - un `INSERT` suivi d'un DDL échoue même sur une base vide : la CI, qui
 *   migre une base vide, l'attrape ;
 * - un `UPDATE` qui ne touche aucune ligne ne met rien en attente : sur la
 *   base vide de la CI, il passe, et le refus n'apparaît que sur une base
 *   peuplée (une base de dev, la préprod) ;
 * - un `UPDATE` d'une colonne que le trigger n'écoute pas, ou un `DELETE`,
 *   ne met rien en attente ; un `RENAME COLUMN`, un `COMMENT` ou un DDL sur
 *   une autre table passent malgré l'attente ;
 * - la parade est `SET CONSTRAINTS … IMMEDIATE` **après** les écritures,
 *   juste avant le DDL — en tête de migration, elle refuse l'écart
 *   transitoire d'un groupe réécrit en plusieurs instructions ;
 * - supprimer et recréer le trigger alors que des écritures attendent leur
 *   contrôle annule ces contrôles sans bruit.
 *
 * Chaque test tourne dans **une** transaction, ouverte au `setUp` (aucune ne
 * doit l'être avant : le harnais n'en ouvre pas, ce qui est vérifié) et
 * annulée au `tearDown` : en PostgreSQL le DDL est transactionnel, rien de ce
 * que le test ajoute ou supprime ne survit. Les lignes de départ sont insérées
 * dans cette même transaction ; leurs propres événements en attente
 * fausseraient chaque cas (un `55006` dû aux `INSERT`, pas au cas), d'où le
 * `IMMEDIATE` puis `DEFERRED` qui les contrôle et vide la file avant le cas.
 */
final class DeferredTriggerPendingEventsTest extends KernelTestCase
{
    private const string TRIGGER = 'trg_translation_group_single_position';

    /** SQLSTATE de PostgreSQL : objet en cours d'utilisation, ici des événements de trigger en attente. */
    private const string OBJECT_IN_USE = '55006';

    private Connection $connection;

    private UuidV7 $group;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertFalse($this->connection->isTransactionActive(), 'Prémisse : aucune transaction ouverte avant le test, sinon les cas mesurent la sienne.');
        $this->connection->beginTransaction();

        $this->group = Uuid::v7();
        foreach (['fr', 'en'] as $locale) {
            $this->connection->executeStatement(
                "INSERT INTO contribution (id, translation_group, locale, position, title, project, reference, url, summary, body)
                 VALUES (?, ?, ?, 900, 'T', 'P', 'R', 'https://example.org', 'S', 'B')",
                [Uuid::v7()->toRfc4122(), $this->group->toRfc4122(), $locale],
            );
        }
        $this->flushPendingEvents();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRefusedDefinitions(): iterable
    {
        yield 'ALTER TABLE ADD COLUMN' => ['ALTER TABLE contribution ADD COLUMN probe_505 integer'];
        yield 'ALTER COLUMN TYPE' => ['ALTER TABLE contribution ALTER COLUMN title TYPE varchar(300)'];
        yield 'DROP COLUMN' => ['ALTER TABLE contribution DROP COLUMN summary'];
        yield 'CREATE INDEX' => ['CREATE INDEX idx_probe_505 ON contribution (title)'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAcceptedDefinitions(): iterable
    {
        yield 'RENAME COLUMN' => ['ALTER TABLE contribution RENAME COLUMN title TO probe_505'];
        yield 'COMMENT' => ["COMMENT ON TABLE contribution IS 'probe 505'"];
        yield 'DDL sur une autre table' => ['ALTER TABLE incident ADD COLUMN probe_505 integer'];
    }

    /**
     * Le cœur du piège : une écriture de position sur une ligne existante,
     * puis un DDL sur la même table, échoue en `55006`.
     */
    #[DataProvider('provideRefusedDefinitions')]
    public function testAPositionWrittenThenAlteredIsRefusedForPendingEvents(string $definition): void
    {
        $this->rewriteTheGroupRowByRow();

        $refusal = $this->refusalOf(fn (): int|string => $this->connection->executeStatement($definition));

        self::assertInstanceOf(DriverException::class, $refusal, 'Le DDL est passé malgré des événements de trigger en attente.');
        self::assertSame(self::OBJECT_IN_USE, $refusal->getSQLState(), $refusal->getMessage());
    }

    /**
     * Tous les DDL ne sont pas refusés : ceux-ci passent malgré les
     * événements en attente.
     */
    #[DataProvider('provideAcceptedDefinitions')]
    public function testSomeDefinitionsPassDespitePendingEvents(string $definition): void
    {
        $this->rewriteTheGroupRowByRow();

        $this->assertPasses($definition);
    }

    /**
     * Un `INSERT` met toujours un événement en attente : la CI, qui migre une
     * base vide, attrape ce cas-là.
     */
    public function testAnInsertThenAnAlterIsRefusedEvenWithoutExistingRows(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO contribution (id, translation_group, locale, position, title, project, reference, url, summary, body)
             VALUES (?, ?, 'fr', 901, 'T', 'P', 'R', 'https://example.org', 'S', 'B')",
            [Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122()],
        );

        $refusal = $this->refusalOf(fn (): int|string => $this->connection->executeStatement('ALTER TABLE contribution ADD COLUMN probe_505 integer'));

        self::assertInstanceOf(DriverException::class, $refusal);
        self::assertSame(self::OBJECT_IN_USE, $refusal->getSQLState(), $refusal->getMessage());
    }

    /**
     * Le trou de la CI : un `UPDATE` qui ne touche aucune ligne — ce qu'est
     * tout `UPDATE` sur la base vide qu'elle migre — ne met rien en attente,
     * et le DDL passe. Le même `UPDATE` sur une base peuplée échoue (cas
     * précédent) : un test de migration doit donc écrire des lignes.
     */
    public function testAnUpdateTouchingNoRowLeavesNothingPending(): void
    {
        $this->connection->executeStatement('UPDATE contribution SET position = position + 1 WHERE translation_group = ?', [Uuid::v7()->toRfc4122()]);

        $this->assertPasses('ALTER TABLE contribution ADD COLUMN probe_505 integer');
    }

    /**
     * Une colonne que le trigger n'écoute pas (`UPDATE OF position,
     * translation_group`) ne met rien en attente.
     */
    public function testAnUpdateOfAnUnwatchedColumnLeavesNothingPending(): void
    {
        $this->connection->executeStatement("UPDATE contribution SET title = 'Autre' WHERE translation_group = ?", [$this->group->toRfc4122()]);

        $this->assertPasses('ALTER TABLE contribution ADD COLUMN probe_505 integer');
    }

    /**
     * Un `DELETE` ne réveille pas le trigger (`AFTER INSERT OR UPDATE OF …`).
     */
    public function testADeleteLeavesNothingPending(): void
    {
        $this->connection->executeStatement('DELETE FROM contribution WHERE translation_group = ?', [$this->group->toRfc4122()]);

        $this->assertPasses('ALTER TABLE contribution ADD COLUMN probe_505 integer');
    }

    /**
     * La parade : `SET CONSTRAINTS … IMMEDIATE` **après** les écritures, juste
     * avant le DDL, contrôle les événements en attente à cet instant et vide
     * la file ; le DDL passe.
     */
    public function testImmediateJustBeforeTheAlterRunsTheCheckAndLetsItThrough(): void
    {
        $this->rewriteTheGroupRowByRow();
        $this->connection->executeStatement(\sprintf('SET CONSTRAINTS %s IMMEDIATE', self::TRIGGER));

        $this->assertPasses('ALTER TABLE contribution ADD COLUMN probe_505 integer');
        self::assertSame([901, 901], $this->positions(), 'Prémisse : le groupe a bien été réécrit.');
    }

    /**
     * … et elle contrôle vraiment : un groupe laissé sur deux positions est
     * refusé à cet instant, en `23T90`.
     */
    public function testImmediateJustBeforeTheAlterStillRefusesADivergentGroup(): void
    {
        $this->connection->executeStatement("UPDATE contribution SET position = 950 WHERE translation_group = ? AND locale = 'en'", [$this->group->toRfc4122()]);

        $refusal = $this->refusalOf(fn (): int|string => $this->connection->executeStatement(\sprintf('SET CONSTRAINTS %s IMMEDIATE', self::TRIGGER)));

        self::assertInstanceOf(DriverException::class, $refusal);
        self::assertSame(TranslationGroupRefusal::ON_SEVERAL_POSITIONS, $refusal->getSQLState(), $refusal->getMessage());
    }

    /**
     * La parade mal placée : `IMMEDIATE` en tête de migration contrôle chaque
     * instruction à sa fin, et refuse en `23T90` l'écart transitoire d'un
     * groupe réécrit ligne à ligne — sur une base peuplée seulement, donc en
     * préprod, pas en CI.
     */
    public function testImmediateAtTheTopRefusesAGroupRewrittenRowByRow(): void
    {
        $this->connection->executeStatement(\sprintf('SET CONSTRAINTS %s IMMEDIATE', self::TRIGGER));

        $refusal = $this->refusalOf($this->rewriteTheGroupRowByRow(...));

        self::assertInstanceOf(DriverException::class, $refusal, 'L\'écart transitoire d\'une réécriture ligne à ligne est passé en mode immédiat.');
        self::assertSame(TranslationGroupRefusal::ON_SEVERAL_POSITIONS, $refusal->getSQLState(), $refusal->getMessage());
    }

    /**
     * Depuis #501, le trigger d'`about_me_card` écoute aussi `category` :
     * déplacer un groupe entier de catégorie, puis altérer la table, échoue
     * de même.
     */
    public function testACategoryWrittenOnAboutMeCardThenAlteredIsRefusedForPendingEvents(): void
    {
        $group = Uuid::v7();
        foreach (['fr', 'en'] as $locale) {
            $this->connection->executeStatement(
                "INSERT INTO about_me_card (id, translation_group, locale, category, position, title, description)
                 VALUES (?, ?, ?, 'hobby', 900, 'T', 'D')",
                [Uuid::v7()->toRfc4122(), $group->toRfc4122(), $locale],
            );
        }
        $this->flushPendingEvents();
        $this->connection->executeStatement("UPDATE about_me_card SET category = 'technical' WHERE translation_group = ?", [$group->toRfc4122()]);

        $refusal = $this->refusalOf(fn (): int|string => $this->connection->executeStatement('ALTER TABLE about_me_card ADD COLUMN probe_505 integer'));

        self::assertInstanceOf(DriverException::class, $refusal);
        self::assertSame(self::OBJECT_IN_USE, $refusal->getSQLState(), $refusal->getMessage());
    }

    /**
     * Supprimer puis recréer le trigger, alors qu'une écriture de la même
     * transaction attend son contrôle, annule ce contrôle sans bruit : le
     * groupe laissé sur deux positions passe. D'où la règle : une migration
     * qui recrée le trigger n'écrit aucune donnée.
     */
    public function testDroppingAndRecreatingTheTriggerCancelsPendingChecksSilently(): void
    {
        $this->connection->executeStatement("UPDATE contribution SET position = 950 WHERE translation_group = ? AND locale = 'en'", [$this->group->toRfc4122()]);

        $this->connection->executeStatement(\sprintf('DROP TRIGGER %s ON contribution', self::TRIGGER));
        $this->connection->executeStatement(\sprintf(
            'CREATE CONSTRAINT TRIGGER %s AFTER INSERT OR UPDATE OF position, translation_group ON contribution'
            .' DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION portfolio_translation_group_single_position()',
            self::TRIGGER,
        ));

        $this->assertPasses(\sprintf('SET CONSTRAINTS %s IMMEDIATE', self::TRIGGER), 'Le contrôle en attente a survécu à la suppression du trigger.');
        self::assertSame([900, 950], $this->positions(), 'Prémisse : le groupe est resté sur deux positions.');
    }

    /**
     * Ce qu'une renumérotation fait : les membres du groupe réécrits un par
     * un, l'écart entre les deux `UPDATE` étant légitime (900 → 901).
     */
    private function rewriteTheGroupRowByRow(): void
    {
        foreach (['fr', 'en'] as $locale) {
            $this->connection->executeStatement(
                'UPDATE contribution SET position = 901 WHERE translation_group = ? AND locale = ?',
                [$this->group->toRfc4122(), $locale],
            );
        }
    }

    /**
     * Contrôle les événements que les `INSERT` de départ ont mis en attente
     * (ils sont cohérents) et vide la file, puis rend au trigger son mode
     * différé : le cas part d'une file vide.
     */
    private function flushPendingEvents(): void
    {
        $this->connection->executeStatement(\sprintf('SET CONSTRAINTS %s IMMEDIATE', self::TRIGGER));
        $this->connection->executeStatement(\sprintf('SET CONSTRAINTS %s DEFERRED', self::TRIGGER));
    }

    /**
     * Le refus de la base, `null` si l'instruction est passée. Un refus laisse
     * la transaction en échec ; le test s'arrête là, le `tearDown` l'annule.
     *
     * @param Closure(): mixed $statement
     */
    private function refusalOf(Closure $statement): ?DriverException
    {
        try {
            $statement();
        } catch (DriverException $refusal) {
            return $refusal;
        }

        return null;
    }

    /**
     * L'instruction doit passer ; un refus fait échouer le test en citant le
     * SQLSTATE et le message de la base, pour qu'on lise la cause.
     */
    private function assertPasses(string $statement, string $why = 'La base a refusé l\'instruction.'): void
    {
        $refusal = $this->refusalOf(fn (): int|string => $this->connection->executeStatement($statement));

        self::assertNull($refusal, null === $refusal ? $why : \sprintf('%s %s : %s', $why, $refusal->getSQLState() ?? '?', $refusal->getMessage()));
    }

    /**
     * @return list<int>
     */
    private function positions(): array
    {
        return array_map(static fn (mixed $position): int => is_numeric($position) ? (int) $position : -1, $this->connection->fetchFirstColumn(
            'SELECT position FROM contribution WHERE translation_group = ? ORDER BY position',
            [$this->group->toRfc4122()],
        ));
    }
}
