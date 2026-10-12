<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Shared\Application;

use App\Portfolio\Contribution\Domain\Entity\Contribution;
use App\Portfolio\Shared\Application\OrderScopeLockInterface;
use App\Portfolio\Shared\Domain\TranslatableContent;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Tests\Support\OpensProbeConnection;
use Closure;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Sous le verrou de périmètre, une opération décide sur ce que la base porte,
 * pas sur ce que l'EntityManager a chargé avant le verrou (issue #402).
 *
 * Le provider d'un `PUT /api/backoffice/<x>/{id}` charge l'entrée **avant**
 * que `update()` ne prenne le verrou (#389). Si un autre `PUT` sur la même
 * entrée la rattachait ailleurs entre-temps, l'instance gérée par Doctrine
 * gardait le groupe d'avant : `reattach()` y voyait un non-geste, `detach()`
 * cherchait l'entrée dans un groupe qu'elle avait quitté, et le 200 décrivait
 * un groupe que la base ne portait pas. Une commande qui enchaîne les
 * opérations sur un même EntityManager (un `app:*:seed`) avait le même défaut
 * pour les **autres** entrées du périmètre.
 *
 * Chaque scénario charge des entrées dans l'EntityManager — ici par leur
 * création, comme le provider dans une requête —, puis une **autre session**
 * PostgreSQL les modifie et valide : c'est l'effet de l'écriture concurrente,
 * tel que l'opération la trouve. Peu importe par où elle est passée, seul
 * compte qu'elle soit validée hors de la carte d'identité de l'ORM.
 *
 * @phpstan-import-type LocalizedContext from ProvidesLocalizedAdministrators
 */
final class ScopeLockReadsCommittedStateTest extends KernelTestCase
{
    use OpensProbeConnection;
    use ProvidesLocalizedAdministrators;

    private ?string $table = null;

    protected function tearDown(): void
    {
        if (null !== $this->table) {
            self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('DELETE FROM '.$this->table);
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
     * Le second onglet voit E dans G et l'y laisse, alors qu'un autre `PUT`
     * l'a mise dans H : la base doit la remettre dans G, à la position de G,
     * et le 200 le dire.
     *
     * @param class-string<TranslatableContent> $entityClass
     * @param Closure(): LocalizedContext       $context
     */
    #[DataProvider('provideLocalizedContexts')]
    public function testKeepingTheGroupAfterAConcurrentMoveWritesItBack(string $entityClass, Closure $context): void
    {
        [$operations, $entry, $translation, $other] = $this->prepare($entityClass, $context);
        $group = $entry->getTranslationGroup();

        $this->moveFromAnotherSession($entityClass, $entry, $other->getTranslationGroup(), $other->getPosition());
        $returned = $operations['update']($entry, $group);

        [$storedGroup, $storedPosition] = $this->storedPlacementOf($entityClass, $entry);
        self::assertTrue($group->equals($storedGroup), "Le groupe demandé n'a pas été écrit : l'entrée est restée là où l'autre PUT l'avait mise (#402).");
        self::assertSame($translation->getPosition(), $storedPosition, "L'entrée n'a pas repris la position de son groupe.");
        self::assertTrue($returned->getTranslationGroup()->equals($storedGroup), 'Le 200 décrit un groupe que la base ne porte pas (#402).');
    }

    /**
     * Le second onglet détache E, qu'un autre `PUT` a mise dans H : la base
     * doit lui donner un groupe à elle **en fin de périmètre** (#169), et le
     * 200 le dire.
     *
     * @param class-string<TranslatableContent> $entityClass
     * @param Closure(): LocalizedContext       $context
     */
    #[DataProvider('provideLocalizedContexts')]
    public function testDetachingAfterAConcurrentMoveDetachesFromTheCurrentGroup(string $entityClass, Closure $context): void
    {
        [$operations, $entry, $translation, $other] = $this->prepare($entityClass, $context);

        $this->moveFromAnotherSession($entityClass, $entry, $other->getTranslationGroup(), $other->getPosition());
        $returned = $operations['update']($entry, null);

        [$storedGroup, $storedPosition] = $this->storedPlacementOf($entityClass, $entry);
        self::assertFalse($other->getTranslationGroup()->equals($storedGroup), "Le détachement a été sauté en silence : l'entrée est restée dans le groupe où l'autre PUT l'avait mise (#402).");
        self::assertSame(max($translation->getPosition(), $other->getPosition()) + 1, $storedPosition, "L'entrée détachée n'est pas partie en fin de périmètre (#169).");
        self::assertTrue($returned->getTranslationGroup()->equals($storedGroup), 'Le 200 décrit un groupe que la base ne porte pas (#402).');
    }

    /**
     * Une entrée supprimée par une autre session entre le chargement et le
     * verrou est introuvable : sans quoi l'`UPDATE` ne touchait aucune ligne
     * et le `PUT` répondait 200 en décrivant une entrée disparue.
     *
     * @param class-string<TranslatableContent> $entityClass
     * @param Closure(): LocalizedContext       $context
     */
    #[DataProvider('provideLocalizedContexts')]
    public function testUpdatingAnEntryDeletedConcurrentlyReportsItAsNotFound(string $entityClass, Closure $context): void
    {
        [$operations, $entry] = $this->prepare($entityClass, $context);

        $this->deleteFromAnotherSession($entityClass, $entry);

        try {
            $operations['update']($entry, $entry->getTranslationGroup());
            self::fail("La mise à jour d'une entrée supprimée a réussi : le 200 décrit une entrée que la base ne porte plus.");
        } catch (DomainException $exception) {
            self::assertStringEndsWith('NotFoundException', $exception::class);
        }
    }

    /**
     * « Créer la version EN » de G, dont le membre FR — déjà chargé — a été
     * déplacé par un réordonnancement validé entre-temps : la version EN doit
     * hériter de la position que G porte en base, pas de celle qu'il quittait.
     *
     * @param class-string<TranslatableContent> $entityClass
     * @param Closure(): LocalizedContext       $context
     */
    #[DataProvider('provideLocalizedContexts')]
    public function testCreatingInAGroupMovedConcurrentlyInheritsItsCurrentPosition(string $entityClass, Closure $context): void
    {
        self::bootKernel();
        $this->table = self::getContainer()->get(EntityManagerInterface::class)->getClassMetadata($entityClass)->getTableName();
        $operations = $context();
        $member = $operations['create'](Locale::FR, null);
        $operations['create'](Locale::FR, null);
        $movedTo = $member->getPosition() + 5;

        $this->moveFromAnotherSession($entityClass, $member, $member->getTranslationGroup(), $movedTo);
        $created = $operations['create'](Locale::EN, $member->getTranslationGroup());

        [, $storedPosition] = $this->storedPlacementOf($entityClass, $created);
        self::assertSame($movedTo, $storedPosition, 'La version créée a hérité de la position que son groupe quittait : le groupe porte deux positions (#402, #389).');
    }

    /**
     * Le correctif repose sur READ COMMITTED : chaque instruction voit ce qui
     * a été validé avant elle, donc la première lecture après le verrou voit
     * l'écriture du détenteur précédent. En REPEATABLE READ, l'instantané
     * serait pris par le `SELECT pg_advisory_xact_lock()` lui-même — avant
     * l'attente — et l'opération relirait l'état d'avant.
     */
    public function testTheLockedOperationRunsAtReadCommitted(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();

        $isolation = self::getContainer()->get(OrderScopeLockInterface::class)->withLock(
            Contribution::class,
            static fn (): mixed => $connection->fetchOne('SHOW transaction_isolation'),
        );

        self::assertSame('read committed', $isolation, "L'opération verrouillée ne tourne plus en READ COMMITTED : elle peut relire l'état d'avant le verrou (#402).");
    }

    /**
     * E (FR) et sa traduction EN dans G, H une entrée EN seule.
     *
     * @param class-string<TranslatableContent> $entityClass
     * @param Closure(): LocalizedContext       $context
     *
     * @return array{0: LocalizedContext, 1: TranslatableContent, 2: TranslatableContent, 3: TranslatableContent}
     */
    private function prepare(string $entityClass, Closure $context): array
    {
        self::bootKernel();
        $this->table = self::getContainer()->get(EntityManagerInterface::class)->getClassMetadata($entityClass)->getTableName();
        $operations = $context();

        $entry = $operations['create'](Locale::FR, null);
        $translation = $operations['create'](Locale::EN, $entry->getTranslationGroup());
        $other = $operations['create'](Locale::EN, null);

        return [$operations, $entry, $translation, $other];
    }

    /**
     * Ce que valide l'écriture concurrente : l'entrée dans ce groupe, à cette
     * position. Écrit par une seconde connexion, donc hors de la carte
     * d'identité de l'ORM.
     *
     * @param class-string<TranslatableContent> $entityClass
     */
    private function moveFromAnotherSession(string $entityClass, TranslatableContent $entry, Uuid $group, int $position): void
    {
        $metadata = self::getContainer()->get(EntityManagerInterface::class)->getClassMetadata($entityClass);

        $this->writeFromAnotherSession(
            \sprintf(
                'UPDATE %s SET %s = ?, %s = ? WHERE %s = ?',
                $metadata->getTableName(),
                $metadata->getColumnName('translationGroup'),
                $metadata->getColumnName('position'),
                $metadata->getColumnName('id'),
            ),
            [$group->toRfc4122(), $position, $this->idOf($entityClass, $entry)],
        );
    }

    /**
     * @param class-string<TranslatableContent> $entityClass
     */
    private function deleteFromAnotherSession(string $entityClass, TranslatableContent $entry): void
    {
        $metadata = self::getContainer()->get(EntityManagerInterface::class)->getClassMetadata($entityClass);

        $this->writeFromAnotherSession(
            \sprintf('DELETE FROM %s WHERE %s = ?', $metadata->getTableName(), $metadata->getColumnName('id')),
            [$this->idOf($entityClass, $entry)],
        );
    }

    /**
     * @param list<int|string> $parameters
     */
    private function writeFromAnotherSession(string $sql, array $parameters): void
    {
        $probe = self::openProbeBeside(self::getContainer()->get(EntityManagerInterface::class)->getConnection());

        try {
            $probe->executeStatement($sql, $parameters);
        } finally {
            $probe->close();
        }
    }

    /**
     * Groupe et position de l'entrée, lus en base.
     *
     * @param class-string<TranslatableContent> $entityClass
     *
     * @return array{0: Uuid, 1: int}
     */
    private function storedPlacementOf(string $entityClass, TranslatableContent $entry): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $metadata = $entityManager->getClassMetadata($entityClass);

        $row = $entityManager->getConnection()->fetchAssociative(
            \sprintf(
                'SELECT %s AS translation_group, %s AS position FROM %s WHERE %s = ?',
                $metadata->getColumnName('translationGroup'),
                $metadata->getColumnName('position'),
                $metadata->getTableName(),
                $metadata->getColumnName('id'),
            ),
            [$this->idOf($entityClass, $entry)],
        );
        self::assertIsArray($row, "L'entrée a disparu de la base.");
        self::assertIsString($row['translation_group']);
        self::assertIsInt($row['position']);

        return [Uuid::fromString($row['translation_group']), $row['position']];
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
