<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Doctrine;

use App\Portfolio\Experience\Domain\Entity\ExperienceTechnology;
use App\Portfolio\Experience\Domain\ValueObject\ExperienceYears;
use App\Portfolio\Experience\Domain\ValueObject\TechnologyName;
use App\Shared\Infrastructure\Doctrine\VanishedEntities;
use App\Shared\Infrastructure\Doctrine\VanishedEntitySavedAgainException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WeakReference;

/**
 * La garde de #548 et #557, sur le conteneur réel : une entité que
 * {@see VanishedEntities} a détachée comme disparue, ou que ce même
 * EntityManager a supprimée, ne repasse pas par `persist()` ; une autre si,
 * et `remove()` puis `persist()` avant le `flush()` annule toujours la
 * suppression. Que les verrous qui détachent passent par elle est couvert par
 * ScopeLockReadsCommittedStateTest, PostgresExperienceTechnologyRowLockTest et
 * PostgresAccountAdministrationLockTest ; que la re-sauvegarde après le
 * `delete()` d'un `Administrator` soit refusée, par les deux premiers
 * (contenus localisés, catalogue de veille, technologies). La suppression
 * d'un compte passe par le même `postRemove`, qui vaut pour toutes les
 * classes : elle n'a pas de test propre. La suppression DQL par identifiant
 * (#569) passe par `deleteById()` (#560), testée ici ; que les deux dépôts qui en
 * font l'appellent, seulement quand la ligne a été touchée, est couvert par
 * CpgUserRepositoryTest et PasswordSetupTokenRepositoryTest.
 */
final class VanishedEntitiesTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->executeStatement('DELETE FROM experience_technology');
    }

    protected function tearDown(): void
    {
        $this->entityManager->getConnection()->executeStatement('DELETE FROM experience_technology');
        parent::tearDown();
    }

    /**
     * L'écouteur est branché sur l'EntityManager du conteneur : le `persist()`
     * d'une entité détachée comme disparue est refusé avant que rien ne soit planifié, sous un
     * message qui la désigne.
     */
    public function testPersistingAVanishedEntityIsRefusedBeforeAnythingIsScheduled(): void
    {
        $technology = $this->storedTechnology();
        $this->vanishedEntities()->detachVanished($this->entityManager, $technology);
        $refusal = null;

        try {
            $this->entityManager->persist($technology);
        } catch (VanishedEntitySavedAgainException $exception) {
            $refusal = $exception;
        }

        self::assertNotNull($refusal, "Le persist() d'une entité détachée comme disparue n'a pas été refusé.");
        self::assertStringContainsString(ExperienceTechnology::class, $refusal->getMessage());
        self::assertStringContainsString($technology->getId()->toRfc4122(), $refusal->getMessage());
        self::assertFalse($this->entityManager->getUnitOfWork()->isScheduledForInsert($technology), 'Le refus a laissé une insertion planifiée.');
    }

    /**
     * `detachVanished()` détache : l'EntityManager ne gère plus l'instance.
     */
    public function testAVanishedEntityIsNoLongerManaged(): void
    {
        $technology = $this->storedTechnology();

        $this->vanishedEntities()->detachVanished($this->entityManager, $technology);

        self::assertFalse($this->entityManager->contains($technology));
    }

    /**
     * Aucun faux positif : la garde retient des objets, pas des identifiants
     * ni des classes. Une autre instance de la même classe, jamais détachée
     * comme disparue, se persiste alors qu'une première vient de l'être.
     */
    public function testAnotherEntityOfTheSameClassIsPersistedNormally(): void
    {
        $vanished = $this->storedTechnology();
        $this->vanishedEntities()->detachVanished($this->entityManager, $vanished);
        $other = new ExperienceTechnology(TechnologyName::fromString('Symfony'), ExperienceYears::fromFloat(2.0));

        $this->entityManager->persist($other);
        $this->entityManager->flush();

        self::assertSame(1, $this->storedCount($other->getId()->toRfc4122()), "Une entité jamais détachée comme disparue n'a pas été écrite.");
    }

    /**
     * Une `WeakMap` : la garde ne retient aucune entité détachée comme disparue en mémoire, ce
     * qui compte dans un worker ou une commande qui enchaîne.
     */
    public function testItHoldsNoVanishedEntityInMemory(): void
    {
        $technology = $this->storedTechnology();
        $this->vanishedEntities()->detachVanished($this->entityManager, $technology);
        $reference = WeakReference::create($technology);

        unset($technology);
        gc_collect_cycles();

        self::assertNull($reference->get(), "La garde retient l'entité détachée comme disparue.");
    }

    /**
     * Une entité que ce même EntityManager a supprimée — `remove()` puis
     * `flush()` — ne revient pas si on la sauve de nouveau (issue #557) :
     * `UnitOfWork::executeDeletions()` efface tout son état et lui laisse son
     * UUID, assigné au constructeur ; `persist()` la tient alors pour neuve et
     * le `flush()` réinsérait la ligne supprimée, sans erreur ni trace. Le
     * refus nomme cette cause, distincte de la ligne disparue sous un verrou.
     */
    public function testPersistingAnEntityThisManagerRemovedIsRefusedUnderItsOwnCause(): void
    {
        $technology = $this->storedTechnology();
        $id = $technology->getId()->toRfc4122();
        $this->entityManager->remove($technology);
        $this->entityManager->flush();
        $refusal = null;

        try {
            $this->entityManager->persist($technology);
        } catch (VanishedEntitySavedAgainException $exception) {
            $refusal = $exception;
        }
        $scheduled = $this->entityManager->getUnitOfWork()->isScheduledForInsert($technology);
        $this->entityManager->flush();

        self::assertSame(0, $this->storedCount($id), 'La technologie supprimée par cet EntityManager, re-sauvée, a été réinsérée (#557).');
        self::assertNotNull($refusal, "Le persist() d'une entité supprimée par cet EntityManager n'a pas été refusé (#557).");
        self::assertFalse($scheduled, 'Le refus a laissé une insertion planifiée.');
        self::assertStringContainsString(ExperienceTechnology::class, $refusal->getMessage());
        self::assertStringContainsString($id, $refusal->getMessage());
        self::assertSame(
            VanishedEntitySavedAgainException::removedByThisManager(ExperienceTechnology::class, $id)->getMessage(),
            $refusal->getMessage(),
            'Le refus ne nomme pas la bonne cause : la suppression par cet EntityManager.',
        );
        self::assertNotSame(
            VanishedEntitySavedAgainException::vanishedUnderLock(ExperienceTechnology::class, $id)->getMessage(),
            $refusal->getMessage(),
            'Les deux causes portent le même message : le journal ne les distingue pas.',
        );
    }

    /**
     * Une suppression DQL par identifiant (issue #569) n'émet pas
     * `postRemove` : `deleteById()` l'exécute et, quand elle a touché la ligne, détache
     * l'instance et la retient sous la cause « supprimée par cet
     * EntityManager », pas « disparue sous un verrou » — la règle écrite une
     * fois pour les dépôts qui en font (issue #560).
     */
    public function testADeleteByIdThatTookTheRowDetachesAndRefusesUnderTheRemovalCause(): void
    {
        $technology = $this->storedTechnology();
        $id = $technology->getId()->toRfc4122();

        $this->vanishedEntities()->deleteById($this->deleteByIdQuery($technology, sparing: false), $technology);
        $refusal = null;
        try {
            $this->entityManager->persist($technology);
        } catch (VanishedEntitySavedAgainException $exception) {
            $refusal = $exception;
        }

        self::assertSame(0, $this->storedCount($id), "La suppression n'a pas été exécutée.");
        self::assertFalse($this->entityManager->contains($technology), "L'entité dont le DELETE a touché la ligne est restée gérée.");
        self::assertNotNull($refusal, "Le persist() d'une entité supprimée en DQL n'a pas été refusé (#569).");
        self::assertSame(VanishedEntitySavedAgainException::removedByThisManager(ExperienceTechnology::class, $id)->getMessage(), $refusal->getMessage());
        self::assertFalse($this->entityManager->getUnitOfWork()->isScheduledForInsert($technology), 'Le refus a laissé une insertion planifiée.');
    }

    /**
     * Une ligne que la condition du `DELETE` a épargnée existe encore : son
     * instance reste gérée, rien n'est retenu. Une ligne déjà disparue ne
     * touche pas plus de ligne, et rien ne la distingue d'une ligne épargnée
     * (limite acceptée par #569).
     */
    public function testADeleteByIdThatTookNoRowLeavesTheInstanceManaged(): void
    {
        $technology = $this->storedTechnology();
        $id = $technology->getId()->toRfc4122();

        $this->vanishedEntities()->deleteById($this->deleteByIdQuery($technology, sparing: true), $technology);

        self::assertSame(1, $this->storedCount($id), 'La condition du DELETE aurait dû épargner la ligne.');
        self::assertTrue($this->entityManager->contains($technology), "L'entité dont la ligne existe encore a été détachée.");
    }

    /**
     * `remove()` puis `persist()` **avant** le `flush()` annule la
     * suppression (`STATE_REMOVED` redevient géré, sans `prePersist`) : un
     * usage légitime de Doctrine, que la garde de #557 ne touche pas — rien
     * n'a été supprimé, rien n'est retenu.
     */
    public function testPersistingBeforeTheFlushStillCancelsTheRemoval(): void
    {
        $technology = $this->storedTechnology();
        $id = $technology->getId()->toRfc4122();

        $this->entityManager->remove($technology);
        $this->entityManager->persist($technology);
        $this->entityManager->flush();

        self::assertSame(1, $this->storedCount($id), 'La suppression annulée avant le flush a quand même supprimé la ligne.');

        // « Rien n'est retenu » ne se lit que sur un chemin qui repasse par
        // `prePersist` : une instance gérée ne l'émet pas. Détachée par un
        // `detach()` ordinaire, sa ligne ôtée en SQL, elle est neuve aux yeux
        // de Doctrine : son `persist()` doit l'écrire, pas être refusé.
        $this->entityManager->detach($technology);
        $this->entityManager->getConnection()->executeStatement('DELETE FROM experience_technology WHERE id = ?', [$id]);
        $this->entityManager->persist($technology);
        $this->entityManager->flush();

        self::assertSame(1, $this->storedCount($id), "La garde a retenu une instance dont la suppression avait été annulée avant le flush (#557).");
    }

    /**
     * Le chemin `postRemove` ne retient pas plus l'entité supprimée que celui
     * du verrou : une commande qui supprime en boucle ne garde rien.
     */
    public function testItHoldsNoRemovedEntityInMemory(): void
    {
        $technology = $this->storedTechnology();
        $this->entityManager->remove($technology);
        $this->entityManager->flush();
        $reference = WeakReference::create($technology);

        unset($technology);
        gc_collect_cycles();

        self::assertNull($reference->get(), "La garde retient l'entité supprimée.");
    }

    private function vanishedEntities(): VanishedEntities
    {
        return self::getContainer()->get(VanishedEntities::class);
    }

    /**
     * Un `DELETE` DQL par identifiant, comme ceux des dépôts ; `sparing`
     * ajoute une condition que la ligne ne remplit pas.
     */
    private function deleteByIdQuery(ExperienceTechnology $technology, bool $sparing): Query
    {
        $builder = $this->entityManager->createQueryBuilder()
            ->delete(ExperienceTechnology::class, 'technology')
            ->where('technology.id = :id')
            ->setParameter('id', $technology->getId(), UuidType::NAME);
        if ($sparing) {
            $builder->andWhere('technology.secondary = true');
        }

        return $builder->getQuery();
    }

    private function storedTechnology(): ExperienceTechnology
    {
        $technology = new ExperienceTechnology(TechnologyName::fromString('PHP'), ExperienceYears::fromFloat(3.0));
        $this->entityManager->persist($technology);
        $this->entityManager->flush();

        return $technology;
    }

    private function storedCount(string $id): int
    {
        $count = $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM experience_technology WHERE id = ?', [$id]);
        self::assertIsInt($count);

        return $count;
    }
}
