<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Doctrine;

use App\Portfolio\Experience\Domain\Entity\ExperienceTechnology;
use App\Portfolio\Experience\Domain\ValueObject\ExperienceYears;
use App\Portfolio\Experience\Domain\ValueObject\TechnologyName;
use App\Shared\Infrastructure\Doctrine\RereadUnderLock;
use App\Shared\Infrastructure\Doctrine\VanishedEntitySavedAgainException;
use App\Tests\Support\ProbesLocks;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * La relecture sous verrou, écrite une fois (issue #560) pour les verrous qui
 * relisent ce que l'EntityManager a chargé avant eux : la technologie (#545),
 * l'administration et la ligne d'un compte (#551, #435), le périmètre d'ordre
 * (#402). Sur la base réelle : rafraîchie sur place, détachée et retenue si
 * la ligne a disparu (#548), jamais une instance dont une écriture est en
 * attente, et `FOR UPDATE` à la demande.
 *
 * Que chaque verrou passe par elle est couvert par ses propres tests
 * (PostgresExperienceTechnologyRowLockTest, PostgresAccountAdministrationLockTest,
 * PostgresAccountRowLockTest, ScopeLockReadsCommittedStateTest) : leurs
 * assertions n'ont pas changé avec l'extraction — seuls leur construction et
 * leurs sondes l'ont fait —, et PostgresAccountRowLockTest a gagné celles du
 * détachement, que ce verrou n'assurait pas avant #560.
 */
final class RereadUnderLockTest extends KernelTestCase
{
    use ProbesLocks;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->executeStatement('DELETE FROM experience_technology');
    }

    protected function tearDown(): void
    {
        $connection = $this->entityManager->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        $connection->executeStatement('DELETE FROM experience_technology');
        parent::tearDown();
    }

    /**
     * La ligne existe : la même instance, rafraîchie sur place — une écriture
     * validée par une autre session depuis son chargement est lue.
     */
    public function testARowStillStoredIsRefreshedInPlace(): void
    {
        $technology = $this->storedTechnology('PHP');
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE experience_technology SET name = ? WHERE id = ?',
            ['Symfony', $technology->getId()->toRfc4122()],
        );

        $reread = $this->reread()->row($this->entityManager, ExperienceTechnology::class, $technology->getId(), forUpdate: false);

        self::assertSame($technology, $reread, "La relecture n'a pas rendu l'instance déjà gérée.");
        self::assertSame('Symfony', $technology->getName(), "L'instance n'a pas été rafraîchie.");
    }

    /**
     * La ligne a disparu : `null`, l'instance détachée et retenue — la sauver
     * de nouveau est refusé sous la cause « disparue sous un verrou » (#548).
     */
    public function testAVanishedRowIsNullAndItsInstanceDetachedAndGuarded(): void
    {
        $technology = $this->storedTechnology('PHP');
        $id = $technology->getId();
        $this->deleteInSql($id);

        $reread = $this->reread()->row($this->entityManager, ExperienceTechnology::class, $id, forUpdate: false);

        self::assertNull($reread);
        self::assertFalse($this->entityManager->contains($technology), "L'instance périmée est restée gérée.");
        $refusal = null;
        try {
            $this->entityManager->persist($technology);
        } catch (VanishedEntitySavedAgainException $exception) {
            $refusal = $exception;
        }
        self::assertNotNull($refusal, "La re-sauvegarde de l'instance disparue n'a pas été refusée.");
        self::assertSame(
            VanishedEntitySavedAgainException::vanishedUnderLock(ExperienceTechnology::class, $id->toRfc4122())->getMessage(),
            $refusal->getMessage(),
        );
    }

    /**
     * Une instance persistée mais pas encore écrite n'a pas de ligne : elle
     * est dite introuvable, mais pas détachée — ce serait annuler en silence
     * son insertion en attente (#402).
     */
    public function testAPendingInsertIsNotFoundButKeptScheduled(): void
    {
        $pending = new ExperienceTechnology(TechnologyName::fromString('Go'), ExperienceYears::fromFloat(1.0));
        $this->entityManager->persist($pending);

        $reread = $this->reread()->row($this->entityManager, ExperienceTechnology::class, $pending->getId(), forUpdate: false);

        self::assertNull($reread);
        self::assertTrue($this->entityManager->getUnitOfWork()->isScheduledForInsert($pending), "L'insertion en attente a été annulée.");
    }

    /**
     * `forUpdate` verrouille la ligne pour la transaction en cours ; sans lui,
     * la relecture ne verrouille rien.
     */
    public function testForUpdateLocksTheRowAndAPlainRereadDoesNot(): void
    {
        $technology = $this->storedTechnology('PHP');
        $connection = $this->entityManager->getConnection();
        $probe = self::openProbeBeside($connection);

        try {
            $connection->beginTransaction();
            $this->reread()->row($this->entityManager, ExperienceTechnology::class, $technology->getId(), forUpdate: false);
            self::assertTrue($this->probeCanLockTheRow($probe, 'experience_technology', $technology->getId()), 'Une relecture simple a verrouillé la ligne.');

            $this->reread()->row($this->entityManager, ExperienceTechnology::class, $technology->getId(), forUpdate: true);
            self::assertFalse($this->probeCanLockTheRow($probe, 'experience_technology', $technology->getId()), "La relecture FOR UPDATE n'a pas verrouillé la ligne.");
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $probe->close();
        }
    }

    /**
     * Toutes les instances gérées d'une classe, en une requête : celles que la
     * base porte encore rafraîchies, les disparues détachées et retenues, une
     * insertion en attente laissée telle quelle.
     */
    public function testEveryManagedInstanceIsRereadVanishedOnesDetachedPendingOnesKept(): void
    {
        $kept = $this->storedTechnology('PHP');
        $vanished = $this->storedTechnology('Perl');
        $pending = new ExperienceTechnology(TechnologyName::fromString('Go'), ExperienceYears::fromFloat(1.0));
        $this->entityManager->persist($pending);
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('UPDATE experience_technology SET name = ? WHERE id = ?', ['Symfony', $kept->getId()->toRfc4122()]);
        $this->deleteInSql($vanished->getId());

        $this->reread()->everyManagedInstanceOf($this->entityManager, ExperienceTechnology::class);

        self::assertSame('Symfony', $kept->getName(), "L'instance encore en base n'a pas été rafraîchie.");
        self::assertTrue($this->entityManager->contains($kept));
        self::assertFalse($this->entityManager->contains($vanished), "L'instance disparue est restée gérée.");
        self::assertTrue($this->entityManager->getUnitOfWork()->isScheduledForInsert($pending), "L'insertion en attente a été annulée.");
        $this->expectException(VanishedEntitySavedAgainException::class);
        $this->entityManager->persist($vanished);
    }

    /**
     * Une classe que Doctrine ne mappe pas n'a aucune instance gérée : rien à
     * relire, et rien ne lève.
     */
    public function testAnUnmappedClassIsANoOp(): void
    {
        $this->reread()->everyManagedInstanceOf($this->entityManager, self::class);

        $this->addToAssertionCount(1);
    }

    private function reread(): RereadUnderLock
    {
        return self::getContainer()->get(RereadUnderLock::class);
    }

    private function storedTechnology(string $name): ExperienceTechnology
    {
        $technology = new ExperienceTechnology(TechnologyName::fromString($name), ExperienceYears::fromFloat(3.0));
        $this->entityManager->persist($technology);
        $this->entityManager->flush();

        return $technology;
    }

    private function deleteInSql(Uuid $id): void
    {
        $this->entityManager->getConnection()->executeStatement('DELETE FROM experience_technology WHERE id = ?', [$id->toRfc4122()]);
    }
}
