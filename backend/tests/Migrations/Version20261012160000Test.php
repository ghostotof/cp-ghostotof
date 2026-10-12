<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Portfolio\Shared\Infrastructure\Doctrine\TranslationGroupRefusal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * #501 : le trigger différé de #390 refuse aussi un groupe de cartes « moi »
 * rangé dans plusieurs catégories. Les groupes déjà répartis ne sont pas
 * corrigés (aucune catégorie n'est sûrement la bonne) : la migration les
 * signale de nouveau en `warning`.
 *
 * La base de test est migrée avant la suite. Chaque test tourne dans une
 * transaction annulée au tearDown : il rejoue `down()` pour retrouver le
 * trigger de #390, écrit ses lignes, puis rejoue `up()`. Le trigger est
 * différé, et un trigger différé ne se déclenche jamais dans une transaction
 * annulée : pour constater un refus, le test le rend immédiat
 * (`SET CONSTRAINTS … IMMEDIATE`) dans un point de sauvegarde.
 */
final class Version20261012160000Test extends KernelTestCase
{
    use ReplaysMigration;

    private const string MIGRATION = 'DoctrineMigrations\Version20261012160000';

    private const string TRIGGER = 'trg_translation_group_single_position';

    private const string FUNCTION = 'portfolio_translation_group_single_position';

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $this->connection->beginTransaction();
        $this->connection->executeStatement('DELETE FROM about_me_card');
        $this->runDown();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    /**
     * Un groupe réparti, que `Version20261012120000` laissait déjà de côté, est
     * signalé avec ses catégories, et laissé tel quel ; un groupe cohérent
     * n'est pas signalé.
     */
    public function testAGroupAcrossTwoCategoriesIsReportedAndLeftUntouched(): void
    {
        $across = Uuid::v7();
        $coherent = Uuid::v7();
        $this->insertAboutMeCard($across, 'fr', 'hobby', 1);
        $this->insertAboutMeCard($across, 'en', 'technical', 1);
        $this->insertAboutMeCard($coherent, 'fr', 'hobby', 2);
        $this->insertAboutMeCard($coherent, 'en', 'hobby', 2);

        $messages = $this->warningsOfUp();
        $this->runUp();

        self::assertCount(1, $messages, 'Seul le groupe réparti est signalé.');
        self::assertStringContainsString('about_me_card', $messages[0]);
        self::assertStringContainsString($across->toRfc4122(), $messages[0]);
        self::assertStringContainsString('hobby, technical', $messages[0]);
        self::assertSame(['hobby 1', 'technical 1'], $this->categoriesAndPositionsOf($across), 'La migration a corrigé un groupe sans savoir quelle catégorie est la bonne.');
    }

    /**
     * Après `up()`, un membre changé seul de catégorie est refusé en `23T91`.
     */
    public function testUpRefusesAMemberMovedAloneToAnotherCategory(): void
    {
        $group = $this->coherentPair();
        $this->runUp();

        $refusal = $this->refusalOfAnImmediateCategoryMove($group);

        self::assertInstanceOf(DriverException::class, $refusal, 'Le trigger posé par up() a laissé passer un groupe réparti.');
        self::assertSame(TranslationGroupRefusal::ACROSS_SEVERAL_SCOPES, $refusal->getSQLState(), $refusal->getMessage());
    }

    /**
     * `down()` rend le trigger de #390 — sans argument, sourd à `category` — et
     * sa fonction, qui ne compare que les positions : un membre passé seul dans
     * une autre catégorie repasse.
     */
    public function testDownRestoresTheGuardOfPositionsAlone(): void
    {
        $group = $this->coherentPair();

        self::assertNull($this->refusalOfAnImmediateCategoryMove($group), 'down() a laissé la garde des catégories en place.');
        self::assertSame(0, $this->connection->fetchOne(
            'SELECT t.tgnargs FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE t.tgname = ? AND c.relname = ?',
            [self::TRIGGER, 'about_me_card'],
        ));
        $function = $this->connection->fetchOne(\sprintf("SELECT pg_get_functiondef('%s()'::regprocedure)", self::FUNCTION));
        self::assertIsString($function);
        self::assertStringNotContainsString('23T91', $function, 'down() a laissé la fonction de #501.');
        self::assertStringContainsString('23T90', $function);
    }

    /**
     * Une paire FR/EN cohérente, dans `hobby` à la position 0.
     */
    private function coherentPair(): UuidV7
    {
        $group = Uuid::v7();
        $this->insertAboutMeCard($group, 'fr', 'hobby', 0);
        $this->insertAboutMeCard($group, 'en', 'hobby', 0);

        return $group;
    }

    /**
     * Passe la version EN du groupe dans `technical`, trigger rendu immédiat,
     * dans un point de sauvegarde annulé ensuite : rend le refus, `null` si
     * l'écriture est passée.
     */
    private function refusalOfAnImmediateCategoryMove(Uuid $group): ?DriverException
    {
        $this->connection->beginTransaction();

        try {
            $this->connection->executeStatement(\sprintf('SET CONSTRAINTS %s IMMEDIATE', self::TRIGGER));
            $this->connection->executeStatement(
                "UPDATE about_me_card SET category = 'technical' WHERE translation_group = ? AND locale = 'en'",
                [$group->toRfc4122()],
            );
        } catch (DriverException $refusal) {
            return $refusal;
        } finally {
            $this->connection->rollBack();
        }

        return null;
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
     * @return list<string> « catégorie position » de chaque membre, par langue
     */
    private function categoriesAndPositionsOf(Uuid $group): array
    {
        /** @var list<string> $rows */
        $rows = $this->connection->fetchFirstColumn(
            "SELECT category || ' ' || position FROM about_me_card WHERE translation_group = ? ORDER BY locale DESC",
            [$group->toRfc4122()],
        );

        return $rows;
    }
}
