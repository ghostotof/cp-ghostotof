<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Doctrine;

use App\Portfolio\Experience\Domain\Entity\ExperienceTechnology;
use App\Portfolio\Experience\Domain\ValueObject\ExperienceYears;
use App\Portfolio\Experience\Domain\ValueObject\TechnologyName;
use App\Shared\Infrastructure\Doctrine\VanishedEntities;
use App\Shared\Infrastructure\Doctrine\VanishedEntitySavedAgainException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WeakReference;

/**
 * La garde de #548 et #557, sur le conteneur réel : une entité que
 * {@see VanishedEntities} a détachée comme disparue, ou que ce même
 * EntityManager a supprimée, ne repasse pas par `persist()` ; une autre si,
 * et `remove()` puis `persist()` avant le `flush()` annule toujours la
 * suppression. Que les verrous qui détachent et les `Administrator`s qui
 * suppriment passent par elle est couvert par ScopeLockReadsCommittedStateTest,
 * PostgresExperienceTechnologyRowLockTest et PostgresAccountAdministrationLockTest.
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

        self::assertSame(1, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM experience_technology WHERE id = ?', [$other->getId()->toRfc4122()]), "Une entité jamais détachée comme disparue n'a pas été écrite.");
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
            $this->entityManager->flush();
        } catch (VanishedEntitySavedAgainException $exception) {
            $refusal = $exception;
        }

        self::assertSame(0, $this->storedCount($id), 'La technologie supprimée par cet EntityManager, re-sauvée, a été réinsérée (#557).');
        self::assertNotNull($refusal, "Le persist() d'une entité supprimée par cet EntityManager n'a pas été refusé (#557).");
        self::assertStringContainsString(ExperienceTechnology::class, $refusal->getMessage());
        self::assertStringContainsString($id, $refusal->getMessage());
        self::assertSame(
            VanishedEntitySavedAgainException::removedByThisManager(ExperienceTechnology::class, $id)->getMessage(),
            $refusal->getMessage(),
            'Le refus ne nomme pas la bonne cause : la suppression par cet EntityManager.',
        );
        self::assertNotSame(
            VanishedEntitySavedAgainException::forEntity(ExperienceTechnology::class, $id)->getMessage(),
            $refusal->getMessage(),
            'Les deux causes portent le même message : le journal ne les distingue pas.',
        );
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

        $this->entityManager->remove($technology);
        $this->entityManager->persist($technology);
        $this->entityManager->flush();
        $this->entityManager->persist($technology);
        $this->entityManager->flush();

        self::assertSame(1, $this->storedCount($technology->getId()->toRfc4122()), "La suppression annulée avant le flush a quand même supprimé la ligne, ou la garde l'a refusée.");
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
