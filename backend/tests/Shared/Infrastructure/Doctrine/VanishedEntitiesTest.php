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
 * La garde de #548, sur le conteneur réel : une entité que {@see VanishedEntities}
 * a détachée ne repasse pas par `persist()`, une autre si. Que les deux verrous
 * qui détachent passent par elle est couvert par ScopeLockReadsCommittedStateTest
 * et PostgresExperienceTechnologyRowLockTest.
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
}
