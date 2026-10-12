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
     * d'une entité oubliée est refusé avant que rien ne soit planifié, sous un
     * message qui la désigne.
     */
    public function testPersistingAForgottenEntityIsRefusedBeforeAnythingIsScheduled(): void
    {
        $technology = $this->storedTechnology();
        $this->vanishedEntities()->forget($this->entityManager, $technology);
        $refusal = null;

        try {
            $this->entityManager->persist($technology);
        } catch (VanishedEntitySavedAgainException $exception) {
            $refusal = $exception;
        }

        self::assertNotNull($refusal, "Le persist() d'une entité oubliée n'a pas été refusé.");
        self::assertStringContainsString(ExperienceTechnology::class, $refusal->getMessage());
        self::assertStringContainsString($technology->getId()->toRfc4122(), $refusal->getMessage());
        self::assertFalse($this->entityManager->getUnitOfWork()->isScheduledForInsert($technology), 'Le refus a laissé une insertion planifiée.');
    }

    /**
     * `forget()` détache : l'EntityManager ne gère plus l'instance.
     */
    public function testAForgottenEntityIsNoLongerManaged(): void
    {
        $technology = $this->storedTechnology();

        $this->vanishedEntities()->forget($this->entityManager, $technology);

        self::assertFalse($this->entityManager->contains($technology));
    }

    /**
     * Aucun faux positif : une entité neuve, jamais oubliée, se persiste.
     */
    public function testAnEntityNeverForgottenIsPersistedNormally(): void
    {
        $this->storedTechnology();
        $other = new ExperienceTechnology(TechnologyName::fromString('Symfony'), ExperienceYears::fromFloat(2.0));

        $this->entityManager->persist($other);
        $this->entityManager->flush();

        self::assertSame(2, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM experience_technology'));
    }

    /**
     * Une `WeakMap` : la garde ne retient aucune entité oubliée en mémoire, ce
     * qui compte dans un worker ou une commande qui enchaîne.
     */
    public function testItHoldsNoForgottenEntityInMemory(): void
    {
        $technology = $this->storedTechnology();
        $this->vanishedEntities()->forget($this->entityManager, $technology);
        $reference = WeakReference::create($technology);

        unset($technology);
        gc_collect_cycles();

        self::assertNull($reference->get(), "La garde retient l'entité oubliée.");
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
