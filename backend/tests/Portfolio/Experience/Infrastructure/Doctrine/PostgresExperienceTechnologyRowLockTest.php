<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Experience\Infrastructure\Doctrine;

use App\Portfolio\Experience\Application\ExperienceTechnologyAdministratorInterface;
use App\Portfolio\Experience\Application\ExperienceTechnologyLockInterface;
use App\Portfolio\Experience\Domain\Entity\ExperienceTechnology;
use App\Portfolio\Experience\Domain\Exception\ExperienceTechnologyNotFoundException;
use App\Portfolio\Experience\Domain\ValueObject\ExperienceYears;
use App\Portfolio\Experience\Domain\ValueObject\TechnologyName;
use App\Portfolio\Experience\Infrastructure\Doctrine\ExperienceTechnologyLockInsideTransactionException;
use App\Portfolio\Experience\Infrastructure\Doctrine\ExperienceTechnologyLockTimeoutException;
use App\Portfolio\Experience\Infrastructure\Doctrine\PostgresExperienceTechnologyRowLock;
use App\Shared\Infrastructure\Doctrine\RereadUnderLock;
use App\Shared\Infrastructure\Doctrine\VanishedEntitySavedAgainException;
use App\Tests\Support\CommitRefusal;
use App\Tests\Support\ProbesLocks;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Le verrou de ligne d'une technologie sur la base réelle (issue #545). Que
 * les écritures du backoffice le prennent est couvert par
 * {@see \App\Tests\Portfolio\Experience\Application\ExperienceTechnologyWritesUnderRowLockTest}.
 */
final class PostgresExperienceTechnologyRowLockTest extends KernelTestCase
{
    use ProbesLocks;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement('DELETE FROM experience_technology');
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('RESET lock_timeout');
        $this->em->getConnection()->executeStatement('DELETE FROM experience_technology');
        parent::tearDown();
    }

    /**
     * L'instance chargée avant le verrou (par le provider) est relue une fois
     * la ligne verrouillée : l'opération voit ce que la base porte.
     */
    public function testTheOperationSeesTheTechnologyAsTheLockReadsIt(): void
    {
        $technology = $this->storedTechnology();
        $this->em->getConnection()->executeStatement('UPDATE experience_technology SET years = 7 WHERE id = :id', ['id' => $technology->getId()->toRfc4122()]);

        $years = $this->lock()->whileLocked($technology->getId(), static fn (ExperienceTechnology $locked): float => $locked->getYears());

        self::assertSame(7.0, $years, "L'opération a lu l'entité d'avant le verrou.");
    }

    /**
     * Une ligne supprimée entre le chargement et le verrou : introuvable, et
     * l'instance périmée quitte l'EntityManager, sans quoi un appelant qui
     * continue (une commande) la croirait encore en base.
     */
    public function testATechnologyDeletedMeanwhileIsANotFoundAndIsDetached(): void
    {
        $technology = $this->storedTechnology();
        $this->em->getConnection()->executeStatement('DELETE FROM experience_technology WHERE id = :id', ['id' => $technology->getId()->toRfc4122()]);
        $ran = false;
        $caught = null;

        try {
            $this->lock()->whileLocked($technology->getId(), static function (ExperienceTechnology $locked) use (&$ran): void {
                $ran = true;
            });
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(ExperienceTechnologyNotFoundException::class, $caught, \sprintf('Une technologie supprimée aurait dû être introuvable : %s.', get_debug_type($caught)));
        self::assertFalse($ran, "L'opération a tourné sur une technologie supprimée.");
        self::assertFalse($this->em->contains($technology), "L'instance périmée est restée gérée.");
        self::assertFalse($this->em->getConnection()->isTransactionActive());
    }

    /**
     * L'instance périmée que le verrou a détachée, re-sauvée par l'appelant qui
     * la tient (une commande qui continue), ne revient pas en base (issue
     * #548) : Doctrine la tiendrait pour neuve, et le `save()` du dépôt
     * réinsérait la technologie supprimée, sans erreur ni trace.
     */
    public function testATechnologyDetachedByTheLockIsRefusedInsteadOfReinsertedWhenSavedAgain(): void
    {
        $technology = $this->storedTechnology();
        $id = $technology->getId()->toRfc4122();
        $this->em->getConnection()->executeStatement('DELETE FROM experience_technology WHERE id = :id', ['id' => $id]);
        try {
            $this->lock()->whileLocked($technology->getId(), static fn (ExperienceTechnology $locked): null => null);
            self::fail("L'opération a tourné sur une technologie supprimée.");
        } catch (ExperienceTechnologyNotFoundException) {
        }
        $refusal = $this->saveAgain($technology);

        self::assertSame(0, $this->storedCount($id), 'La technologie supprimée, détachée par le verrou puis re-sauvée, a été réinsérée (#548).');
        self::assertInstanceOf(VanishedEntitySavedAgainException::class, $refusal, \sprintf('La re-sauvegarde aurait dû être refusée sous son nom : %s.', get_debug_type($refusal)));
    }

    /**
     * La technologie supprimée par `ExperienceTechnologyAdministrator::delete()`
     * — l'instance que l'appelant tient, relue en place sous le verrou —, puis
     * re-sauvée, ne revient pas (issue #557) : après le `flush()` du `DELETE`,
     * Doctrine la tenait pour neuve et la réinsérait au même UUID.
     */
    public function testATechnologyThisManagerDeletedIsRefusedInsteadOfReinsertedWhenSavedAgain(): void
    {
        $technology = $this->storedTechnology();
        $id = $technology->getId()->toRfc4122();
        self::getContainer()->get(ExperienceTechnologyAdministratorInterface::class)->delete($technology->getId());
        $refusal = $this->saveAgain($technology);

        self::assertSame(0, $this->storedCount($id), 'La technologie supprimée par son Administrator, re-sauvée, a été réinsérée (#557).');
        self::assertInstanceOf(VanishedEntitySavedAgainException::class, $refusal, \sprintf('La re-sauvegarde aurait dû être refusée sous son nom : %s.', get_debug_type($refusal)));
    }

    public function testAnUnknownTechnologyIsANotFound(): void
    {
        $this->expectException(ExperienceTechnologyNotFoundException::class);

        $this->lock()->whileLocked(Uuid::v7(), static fn (ExperienceTechnology $locked): null => null);
    }

    /**
     * Un refus levé par l'opération (le 409 d'un nom déjà pris) est une issue
     * ordinaire : la transaction est annulée, l'EntityManager reste ouvert.
     */
    public function testARefusalInsideTheOperationLeavesTheEntityManagerOpen(): void
    {
        $technology = $this->storedTechnology();
        $caught = null;

        try {
            $this->lock()->whileLocked($technology->getId(), static function (ExperienceTechnology $locked): never {
                throw new LogicException('refus');
            });
        } catch (LogicException $exception) {
            $caught = $exception;
        }

        self::assertSame('refus', $caught->getMessage(), "L'exception de l'opération aurait dû remonter telle quelle.");
        self::assertTrue($this->em->isOpen());
        self::assertFalse($this->em->getConnection()->isTransactionActive());
    }

    /**
     * Une écriture que la base refuse au `flush()` de l'opération (le chemin du
     * 409 de `update()` quand la vérification du nom a été devancée) : le
     * `flush()` échoue à son point de sauvegarde et ferme l'EntityManager, le
     * squelette annule la transaction, et l'exception de la base remonte telle
     * quelle, sans être masquée par le ROLLBACK.
     */
    public function testAFlushRefusedInsideTheOperationRollsBackAndSurfacesAsIs(): void
    {
        $technology = $this->storedTechnology();
        $this->em->persist(new ExperienceTechnology(TechnologyName::fromString('Symfony'), ExperienceYears::fromFloat(1.0)));
        $this->em->flush();
        $caught = null;

        try {
            $this->lock()->whileLocked($technology->getId(), function (ExperienceTechnology $locked): void {
                $locked->update(TechnologyName::fromString('Symfony'), ExperienceYears::fromFloat(2.0), null, null, false);
                $this->em->flush();
            });
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(UniqueConstraintViolationException::class, $caught, \sprintf('Le refus du flush est remonté sous un autre nom : %s.', get_debug_type($caught)));
        self::assertFalse($this->em->getConnection()->isTransactionActive());
        self::assertSame('PHP', $this->em->getConnection()->fetchOne('SELECT name FROM experience_technology WHERE id = :id', ['id' => $technology->getId()->toRfc4122()]));
    }

    /**
     * Issue #547 : le `flush()` de l'opération a réussi au point de
     * sauvegarde, puis la base a refusé le COMMIT — ici une contrainte
     * différée sur une table temporaire, la table des technologies n'en ayant
     * aucune : en production, seule une session perdue à ce moment-là le
     * refuse. L'UnitOfWork tenait pour écrit ce que le refus venait d'annuler :
     * le squelette ferme donc l'EntityManager, le refus remonte tel quel, et
     * une nouvelle lecture relit la base.
     */
    public function testACommitRefusedByTheDatabaseClosesTheEntityManagerOnItsRefusedWrites(): void
    {
        $technology = $this->storedTechnology();
        $connection = $this->em->getConnection();
        $caught = null;

        try {
            $this->lock()->whileLocked($technology->getId(), function (ExperienceTechnology $locked) use ($connection): void {
                $locked->update(TechnologyName::fromString('PHP'), ExperienceYears::fromFloat(9.0), null, null, false);
                $this->em->flush();
                CommitRefusal::scheduleIn($connection);
            });
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(DriverException::class, $caught, \sprintf('Le COMMIT refusé est remonté sous un autre nom : %s.', get_debug_type($caught)));
        self::assertSame(CommitRefusal::SQLSTATE, $caught->getSQLState());
        self::assertFalse($connection->isTransactionActive());
        self::assertFalse($this->em->isOpen(), 'Un COMMIT refusé a laissé l\'EntityManager ouvert sur une écriture annulée.');
        self::assertSame(3.0, $this->em->find(ExperienceTechnology::class, $technology->getId())?->getYears(), 'Une lecture après le refus rend la durée que la base a refusée.');
    }

    /**
     * Une instance persistée mais pas encore écrite n'a pas de ligne : elle est
     * introuvable, mais son insertion en attente n'est pas annulée.
     */
    public function testAPendingInsertIsANotFoundButIsNotDetached(): void
    {
        $technology = new ExperienceTechnology(TechnologyName::fromString('Go'), ExperienceYears::fromFloat(1.0));
        $this->em->persist($technology);
        $caught = null;

        try {
            $this->lock()->whileLocked($technology->getId(), static fn (ExperienceTechnology $locked): null => null);
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(ExperienceTechnologyNotFoundException::class, $caught, \sprintf('Une technologie pas encore écrite aurait dû être introuvable : %s.', get_debug_type($caught)));
        self::assertTrue($this->em->getUnitOfWork()->isScheduledForInsert($technology), "L'insertion en attente a été annulée.");
    }

    /**
     * L'attente est bornée dans la transaction elle-même quand la session ne
     * la borne pas (la console, la CI), comme pour les autres verrous du
     * squelette.
     */
    public function testTheWaitIsBoundedInsideTheTransaction(): void
    {
        $technology = $this->storedTechnology();
        $this->em->getConnection()->executeStatement('SET lock_timeout = 0');

        $timeout = $this->lock()->whileLocked($technology->getId(), fn (ExperienceTechnology $locked): mixed => $this->em->getConnection()->fetchOne('SHOW lock_timeout'));

        self::assertSame('5s', $timeout);
    }

    /**
     * Au-delà du délai, une exception nommée, pas la `DriverException`
     * anonyme de PostgreSQL. Le message ne cite que l'UUID.
     */
    public function testALockHeldBeyondTheTimeoutRaisesANamedException(): void
    {
        $technology = $this->storedTechnology();
        $holder = self::openProbeBeside($this->em->getConnection());
        $holder->beginTransaction();
        $holder->fetchOne('SELECT 1 FROM experience_technology WHERE id = :id FOR UPDATE', ['id' => $technology->getId()->toRfc4122()]);
        $this->em->getConnection()->executeStatement('SET lock_timeout = 0');
        // Filet : si la borne venait à manquer, l'attente échouerait en 57014
        // après 3 s au lieu de pendre.
        $this->em->getConnection()->executeStatement("SET statement_timeout = '3s'");
        $caught = null;

        try {
            (new PostgresExperienceTechnologyRowLock($this->em, new NullLogger(), self::getContainer()->get(RereadUnderLock::class), '50ms'))->whileLocked($technology->getId(), static fn (ExperienceTechnology $locked): null => null);
        } catch (Throwable $exception) {
            $caught = $exception;
        } finally {
            $holder->rollBack();
            $holder->close();
            $this->em->getConnection()->executeStatement('RESET statement_timeout');
        }

        self::assertInstanceOf(ExperienceTechnologyLockTimeoutException::class, $caught, \sprintf("L'attente du verrou n'a pas échoué sous son nom : %s.", get_debug_type($caught)));
        self::assertStringContainsString($technology->getId()->toRfc4122(), $caught->getMessage());
    }

    /**
     * Comme les autres verrous du squelette (#532) : dans une transaction déjà
     * ouverte, son COMMIT ne serait qu'un `RELEASE SAVEPOINT`, et la ligne
     * resterait verrouillée jusqu'au COMMIT de l'appelant. Refusé avant de
     * verrouiller la ligne ni de lancer l'opération.
     */
    public function testTheLockRefusesToRunInsideATransactionAlreadyOpen(): void
    {
        $technology = $this->storedTechnology();
        $connection = $this->em->getConnection();
        $probe = self::openProbeBeside($connection);
        $connection->beginTransaction();
        $ran = false;
        $caught = null;

        try {
            $this->lock()->whileLocked($technology->getId(), static function (ExperienceTechnology $locked) use (&$ran): void {
                $ran = true;
            });
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        try {
            self::assertInstanceOf(ExperienceTechnologyLockInsideTransactionException::class, $caught, \sprintf("L'imbrication n'a pas été refusée sous son nom : %s.", get_debug_type($caught)));
            self::assertStringContainsString($technology->getId()->toRfc4122(), $caught->getMessage());
            self::assertFalse($ran, "L'opération a tourné dans la transaction de l'appelant.");
            self::assertSame(1, $connection->getTransactionNestingLevel(), "La transaction de l'appelant a été touchée.");
            self::assertTrue($this->probeCanLockTheRow($probe, 'experience_technology', $technology->getId()), 'La ligne a été verrouillée malgré le refus.');
        } finally {
            $connection->rollBack();
            $probe->close();
        }
    }

    private function lock(): ExperienceTechnologyLockInterface
    {
        return self::getContainer()->get(ExperienceTechnologyLockInterface::class);
    }

    /**
     * Re-sauve l'instance comme le `save()` du dépôt — `persist()` puis
     * `flush()` — et rend ce que cela a levé.
     */
    private function saveAgain(ExperienceTechnology $technology): ?Throwable
    {
        try {
            $this->em->persist($technology);
            $this->em->flush();
        } catch (Throwable $refusal) {
            return $refusal;
        }

        return null;
    }

    private function storedCount(string $id): int
    {
        $count = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM experience_technology WHERE id = :id', ['id' => $id]);
        self::assertIsInt($count);

        return $count;
    }

    private function storedTechnology(): ExperienceTechnology
    {
        $technology = new ExperienceTechnology(TechnologyName::fromString('PHP'), ExperienceYears::fromFloat(3.0));
        $this->em->persist($technology);
        $this->em->flush();

        return $technology;
    }

}
