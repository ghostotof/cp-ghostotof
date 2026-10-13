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
 * (#390, #501). Le piège, sa parade et la décision de ne pas ajouter de garde
 * automatique sont écrits dans `.claude/rules/portfolio-contenus.md`
 * (« Trap for future migrations »), qui fait référence ; ce test en épingle
 * les **faits**, mesurés sur le vrai schéma, pour qu'une montée de version de
 * PostgreSQL ou un changement du trigger qui les contredirait fasse tomber la
 * règle en même temps.
 *
 * Un trigger différé laisse un événement en attente pour chaque ligne qu'une
 * écriture surveillée touche, jusqu'au COMMIT, et PostgreSQL refuse alors en
 * SQLSTATE `55006` les `ALTER TABLE` et les `CREATE INDEX` sur cette table.
 *
 * Chaque test tourne dans **une** transaction, ouverte au `setUp` (aucune ne
 * doit l'être avant : le dépôt n'enveloppe pas les tests dans une
 * transaction, ce qui est vérifié) et annulée au `tearDown` : en PostgreSQL
 * le DDL est transactionnel, rien de ce que le test ajoute ou supprime ne
 * survit. Les lignes de départ sont insérées dans cette même transaction ;
 * leurs propres événements en attente fausseraient chaque cas (un `55006` dû
 * à ces `INSERT`, pas au cas), d'où le `IMMEDIATE` puis `DEFERRED` qui les
 * contrôle et vide la file avant le cas — les cas qui doivent passer
 * prouvent que la file est bien vide au départ.
 */
final class DeferredTriggerPendingEventsTest extends KernelTestCase
{
    private const string TRIGGER = 'trg_translation_group_single_position';

    /** SQLSTATE de PostgreSQL : objet en cours d'utilisation, ici des événements de trigger en attente. */
    private const string OBJECT_IN_USE = '55006';

    /** Le DDL type d'une migration : la table gagne une colonne (annulée avec la transaction). */
    private const string ADD_COLUMN = 'ALTER TABLE contribution ADD COLUMN probe_505 integer';

    private const string IMMEDIATE = 'SET CONSTRAINTS '.self::TRIGGER.' IMMEDIATE';

    private const string DEFERRED = 'SET CONSTRAINTS '.self::TRIGGER.' DEFERRED';

    private const string INSERT_CONTRIBUTION = "INSERT INTO contribution (id, translation_group, locale, position, title, project, reference, url, summary, body)
        VALUES (?, ?, ?, ?, 'T', 'P', 'R', 'https://example.org', 'S', 'B')";

    private Connection $connection;

    private UuidV7 $group;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertFalse($this->connection->isTransactionActive(), 'Prémisse : aucune transaction ouverte avant le test, sinon les cas mesurent la sienne.');
        $this->connection->beginTransaction();

        $this->group = Uuid::v7();
        $this->insertContribution($this->group, 'fr', 900);
        $this->insertContribution($this->group, 'en', 900);
        $this->flushPendingEvents();
    }

    /**
     * `isset()` d'abord (issue #585) : si `setUp()` échoue avant d'affecter
     * la connexion (démarrage du noyau, conteneur), lire la propriété lèverait
     * une `Error` d'initialisation. PHPUnit 13 garderait bien l'exception de
     * `setUp()` et avalerait celle-ci sans trace (mesuré), mais
     * `parent::tearDown()` serait sauté : le noyau démarré resterait en place
     * jusqu'au prochain `bootKernel()`.
     */
    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    /**
     * Toute sous-commande d'`ALTER TABLE` mesurée est refusée, et
     * `CREATE INDEX` aussi.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideRefusedDefinitions(): iterable
    {
        yield 'ADD COLUMN' => [self::ADD_COLUMN];
        yield 'ALTER COLUMN TYPE' => ['ALTER TABLE contribution ALTER COLUMN title TYPE varchar(300)'];
        yield 'DROP COLUMN' => ['ALTER TABLE contribution DROP COLUMN summary'];
        yield 'SET NOT NULL' => ['ALTER TABLE contribution ALTER COLUMN title SET NOT NULL'];
        yield 'SET DEFAULT' => ["ALTER TABLE contribution ALTER COLUMN title SET DEFAULT 'x'"];
        yield 'ADD CONSTRAINT' => ['ALTER TABLE contribution ADD CONSTRAINT chk_probe_505 CHECK (position >= 0)'];
        yield 'DISABLE TRIGGER' => ['ALTER TABLE contribution DISABLE TRIGGER '.self::TRIGGER];
        yield 'CREATE INDEX' => ['CREATE INDEX idx_probe_505 ON contribution (title)'];
    }

    /**
     * Ce qui n'est pas un `ALTER TABLE` de la table passe.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideAcceptedDefinitions(): iterable
    {
        yield 'RENAME COLUMN' => ['ALTER TABLE contribution RENAME COLUMN title TO probe_505'];
        yield 'COMMENT' => ["COMMENT ON TABLE contribution IS 'probe 505'"];
        yield 'DROP INDEX' => ['DROP INDEX idx_contribution_locale_position'];
        yield 'DDL sur une autre table' => ['ALTER TABLE incident ADD COLUMN probe_505 integer'];
    }

    /**
     * Le cœur du piège : une écriture de position sur des lignes existantes,
     * puis un DDL sur la même table, échoue en `55006`.
     */
    #[DataProvider('provideRefusedDefinitions')]
    public function testAPositionWrittenThenAlteredIsRefusedForPendingEvents(string $definition): void
    {
        $this->rewriteTheGroupRowByRow();

        $this->assertRefusedWith(self::OBJECT_IN_USE, $definition);
    }

    #[DataProvider('provideAcceptedDefinitions')]
    public function testSomeDefinitionsPassDespitePendingEvents(string $definition): void
    {
        $this->rewriteTheGroupRowByRow();

        $this->assertPasses($definition);
    }

    /**
     * Sur une table vide — ce que la CI migre —, un `INSERT` met un événement
     * en attente : la CI attrape un `INSERT` suivi d'un DDL.
     */
    public function testAnInsertIntoAnEmptyTableThenAnAlterIsRefused(): void
    {
        $this->connection->executeStatement('DELETE FROM contribution');
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM contribution'), 'Prémisse : la table est vide.');
        $this->insertContribution(Uuid::v7(), 'fr', 901);

        $this->assertRefusedWith(self::OBJECT_IN_USE, self::ADD_COLUMN);
    }

    /**
     * … mais seulement s'il insère des lignes : un `INSERT … SELECT` dont la
     * source est vide en CI ne met rien en attente, et passe.
     */
    public function testAnInsertOfNoRowLeavesNothingPending(): void
    {
        $this->connection->executeStatement('INSERT INTO contribution SELECT * FROM contribution WHERE false');

        $this->assertPasses(self::ADD_COLUMN);
    }

    /**
     * Le trou de la CI : un `UPDATE` qui ne touche aucune ligne — ce qu'est
     * tout `UPDATE` sur la base vide qu'elle migre — ne met rien en attente,
     * et le DDL passe. Le même `UPDATE` sur une base peuplée échoue : un test
     * de migration doit donc écrire des lignes.
     */
    public function testAnUpdateTouchingNoRowLeavesNothingPending(): void
    {
        $this->connection->executeStatement('UPDATE contribution SET position = position + 1 WHERE translation_group = ?', [Uuid::v7()->toRfc4122()]);

        $this->assertPasses(self::ADD_COLUMN);
    }

    /**
     * Une colonne que le trigger n'écoute pas (`UPDATE OF position,
     * translation_group`) ne met rien en attente.
     */
    public function testAnUpdateOfAnUnwatchedColumnLeavesNothingPending(): void
    {
        $this->connection->executeStatement("UPDATE contribution SET title = 'Autre' WHERE translation_group = ?", [$this->group->toRfc4122()]);

        $this->assertPasses(self::ADD_COLUMN);
    }

    /**
     * Un `DELETE` ne réveille pas le trigger (`AFTER INSERT OR UPDATE OF …`).
     */
    public function testADeleteLeavesNothingPending(): void
    {
        $this->connection->executeStatement('DELETE FROM contribution WHERE translation_group = ?', [$this->group->toRfc4122()]);

        $this->assertPasses(self::ADD_COLUMN);
    }

    /**
     * La parade : `IMMEDIATE` **après** les écritures, juste avant le DDL,
     * contrôle les événements en attente à cet instant et vide la file ; le
     * DDL passe. `DEFERRED` rétabli ensuite, une réécriture ligne à ligne qui
     * suit le DDL passe aussi.
     */
    public function testImmediateJustBeforeTheAlterRunsTheCheckAndLetsItThrough(): void
    {
        $this->rewriteTheGroupRowByRow();
        $this->connection->executeStatement(self::IMMEDIATE);

        $this->assertPasses(self::ADD_COLUMN);

        $this->connection->executeStatement(self::DEFERRED);
        $this->assertPasses(function (): void {
            $this->rewriteTheGroupRowByRow(902);
        }, 'Une réécriture ligne à ligne après le DDL, mode différé rétabli, est refusée.');
        self::assertSame([902, 902], $this->positions());
    }

    /**
     * Sans `DEFERRED` rétabli, le mode immédiat reste en vigueur pour la suite
     * de la migration : une réécriture ligne à ligne après le DDL est refusée.
     */
    public function testImmediateLeftInForceRefusesARowByRowRewriteAfterTheAlter(): void
    {
        $this->connection->executeStatement(self::IMMEDIATE);
        $this->assertPasses(self::ADD_COLUMN);

        $this->assertRefusedWith(TranslationGroupRefusal::ON_SEVERAL_POSITIONS, $this->rewriteTheGroupRowByRow(...));
    }

    /**
     * `IMMEDIATE` contrôle vraiment : un groupe laissé sur deux positions est
     * refusé à cette ligne, en `23T90`.
     */
    public function testImmediateJustBeforeTheAlterStillRefusesADivergentGroup(): void
    {
        $this->connection->executeStatement("UPDATE contribution SET position = 950 WHERE translation_group = ? AND locale = 'en'", [$this->group->toRfc4122()]);

        $this->assertRefusedWith(TranslationGroupRefusal::ON_SEVERAL_POSITIONS, self::IMMEDIATE);
    }

    /**
     * La parade mal placée : `IMMEDIATE` en tête de migration contrôle chaque
     * instruction à sa fin, et refuse en `23T90` l'écart transitoire d'un
     * groupe réécrit ligne à ligne — sur une base peuplée seulement, donc en
     * préprod, pas en CI.
     */
    public function testImmediateAtTheTopRefusesAGroupRewrittenRowByRow(): void
    {
        $this->connection->executeStatement(self::IMMEDIATE);

        $this->assertRefusedWith(TranslationGroupRefusal::ON_SEVERAL_POSITIONS, $this->rewriteTheGroupRowByRow(...));
    }

    /**
     * L'autre parade : le DDL d'abord, les écritures ensuite.
     */
    public function testTheAlterFirstThenTheWritesPasses(): void
    {
        $this->assertPasses(self::ADD_COLUMN);

        $this->assertPasses($this->rewriteTheGroupRowByRow(...));
        self::assertSame([901, 901], $this->positions());
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

        $this->assertRefusedWith(self::OBJECT_IN_USE, 'ALTER TABLE about_me_card ADD COLUMN probe_505 integer');
    }

    /**
     * Supprimer puis recréer le trigger — à l'identique, sa définition lue en
     * base —, alors qu'une écriture de la même transaction attend son
     * contrôle, annule ce contrôle sans bruit : le groupe laissé sur deux
     * positions passe un `IMMEDIATE` ultérieur (et le COMMIT, mesuré pour
     * #505 ; un test qui valide ne s'annulerait pas). D'où la règle : une
     * migration qui recrée le trigger n'écrit aucune donnée.
     */
    public function testDroppingAndRecreatingTheTriggerCancelsPendingChecksSilently(): void
    {
        $definition = $this->connection->fetchOne(
            "SELECT pg_get_triggerdef(oid) FROM pg_trigger WHERE tgname = ? AND tgrelid = 'contribution'::regclass",
            [self::TRIGGER],
        );
        self::assertIsString($definition, 'Prémisse : la table porte le trigger.');
        $this->connection->executeStatement("UPDATE contribution SET position = 950 WHERE translation_group = ? AND locale = 'en'", [$this->group->toRfc4122()]);

        $this->connection->executeStatement(\sprintf('DROP TRIGGER %s ON contribution', self::TRIGGER));
        $this->connection->executeStatement($definition);

        $this->assertPasses(self::IMMEDIATE, 'Le contrôle en attente a survécu à la suppression du trigger.');
        self::assertSame([900, 950], $this->positions(), 'Le groupe devait rester sur deux positions.');
    }

    /**
     * Ce qu'une renumérotation fait : les membres du groupe réécrits un par
     * un, l'écart entre les deux `UPDATE` étant légitime.
     */
    private function rewriteTheGroupRowByRow(int $position = 901): void
    {
        $this->connection->executeStatement(
            'UPDATE contribution SET position = ? WHERE translation_group = ? AND locale = ?',
            [$position, $this->group->toRfc4122(), 'fr'],
        );
        $this->connection->executeStatement(
            'UPDATE contribution SET position = ? WHERE translation_group = ? AND locale = ?',
            [$position, $this->group->toRfc4122(), 'en'],
        );
    }

    private function insertContribution(Uuid $group, string $locale, int $position): void
    {
        $this->connection->executeStatement(self::INSERT_CONTRIBUTION, [Uuid::v7()->toRfc4122(), $group->toRfc4122(), $locale, $position]);
    }

    /**
     * Contrôle les événements que les `INSERT` de départ ont mis en attente
     * (ils sont cohérents) et vide la file, puis rend au trigger son mode
     * différé : le cas part d'une file vide.
     */
    private function flushPendingEvents(): void
    {
        $this->connection->executeStatement(self::IMMEDIATE);
        $this->connection->executeStatement(self::DEFERRED);
    }

    /**
     * L'instruction (du SQL, ou une suite d'instructions) doit être refusée
     * sous ce SQLSTATE. Un refus laisse la transaction en échec ; le test
     * s'arrête là, le `tearDown` l'annule.
     *
     * @param string|Closure(): void $statement
     */
    private function assertRefusedWith(string $sqlState, string|Closure $statement): void
    {
        $refusal = $this->refusalOf($statement);

        self::assertInstanceOf(DriverException::class, $refusal, \sprintf('La base devait refuser en %s, l\'instruction est passée.', $sqlState));
        self::assertSame($sqlState, $refusal->getSQLState(), $refusal->getMessage());
    }

    /**
     * L'instruction doit passer ; un refus fait échouer le test en citant le
     * SQLSTATE et le message de la base, pour qu'on lise la cause.
     *
     * @param string|Closure(): void $statement
     */
    private function assertPasses(string|Closure $statement, string $why = 'La base a refusé l\'instruction.'): void
    {
        $refusal = $this->refusalOf($statement);

        self::assertNull($refusal, null === $refusal ? $why : \sprintf('%s %s : %s', $why, $refusal->getSQLState() ?? '?', $refusal->getMessage()));
    }

    /**
     * Le refus de la base, `null` si l'instruction est passée.
     *
     * @param string|Closure(): void $statement
     */
    private function refusalOf(string|Closure $statement): ?DriverException
    {
        try {
            if (\is_string($statement)) {
                $this->connection->executeStatement($statement);
            } else {
                $statement();
            }
        } catch (DriverException $refusal) {
            return $refusal;
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private function positions(): array
    {
        /** @var list<int> $positions */
        $positions = $this->connection->fetchFirstColumn(
            'SELECT position FROM contribution WHERE translation_group = ? ORDER BY position',
            [$this->group->toRfc4122()],
        );

        return $positions;
    }
}
