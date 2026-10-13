<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Infrastructure\Doctrine;

use App\Security\User\Application\AccountAdministrationLockInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\ActingAccountNoLongerSuperAdminException;
use App\Security\User\Domain\Exception\CpgUserNotFoundException;
use App\Security\User\Infrastructure\Doctrine\AccountAdministrationLockInsideTransactionException;
use App\Security\User\Infrastructure\Doctrine\AccountAdministrationLockTimeoutException;
use App\Security\User\Infrastructure\Doctrine\PostgresAccountAdministrationLock;
use App\Shared\Infrastructure\Doctrine\RereadUnderLock;
use App\Shared\Infrastructure\Doctrine\VanishedEntitySavedAgainException;
use App\Tests\Support\CommitRefusal;
use App\Tests\Support\ProbesLocks;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

/**
 * Le verrou d'administration des comptes sur la base réelle (issues #551,
 * #552). Sa raison d'être, la sérialisation de deux super-administrateurs qui
 * agissent l'un contre l'autre, est couverte par
 * AccountAdministrationConcurrencyTest ; l'effet sur les cas d'usage par
 * AccountAdministrationStaleStateTest.
 */
final class PostgresAccountAdministrationLockTest extends KernelTestCase
{
    use ProbesLocks;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement('DELETE FROM cpg_user');
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->executeStatement('RESET lock_timeout');
        $this->em->getConnection()->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    public function testTheOperationSeesTheTargetAsTheLockReadsIt(): void
    {
        $actor = $this->superAdmin('alpha');
        $target = $this->superAdmin('beta');
        $this->em->getConnection()->executeStatement("UPDATE cpg_user SET roles = '[]' WHERE id = :id", ['id' => $target->getId()->toRfc4122()]);

        $roles = $this->lock()->whileAdministering($target, $actor, static fn (CpgUser $locked): array => $locked->getRoles());

        self::assertNotContains(CpgUser::ROLE_SUPER, $roles, 'L\'opération a lu l\'entité d\'avant le verrou.');
        self::assertSame($target, $this->em->find(CpgUser::class, $target->getId()), 'La relecture a remplacé l\'instance gérée au lieu de la rafraîchir.');
    }

    /**
     * Pris pendant l'opération, vu d'une autre session ; rendu à sa fin, avec
     * la transaction qui le portait. L'advisory lock global et la ligne du
     * compte administré ; la ligne de l'acteur, elle, n'est jamais tenue.
     */
    public function testTheAdvisoryLockAndTheTargetRowAreHeldDuringTheOperationAndReleasedWithItsTransaction(): void
    {
        $actor = $this->superAdmin('alpha');
        $target = $this->superAdmin('beta');
        $probe = self::openProbeBeside($this->em->getConnection());

        try {
            [$advisoryFree, $targetRowFree, $actorRowFree] = $this->lock()->whileAdministering($target, $actor, fn (CpgUser $locked): array => [
                $this->administrationLockIsFree($probe),
                $this->probeCanLockTheRow($probe, 'cpg_user', $target->getId()),
                $this->probeCanLockTheRow($probe, 'cpg_user', $actor->getId()),
            ]);

            self::assertFalse($advisoryFree, 'Une autre session a pu prendre l\'advisory lock pendant l\'opération.');
            self::assertFalse($targetRowFree, 'Une autre session a pu verrouiller la ligne du compte administré pendant l\'opération.');
            self::assertTrue($actorRowFree, 'La ligne de l\'acteur a été verrouillée : sa relecture doit rester sans verrou.');
            self::assertTrue($this->administrationLockIsFree($probe), 'L\'advisory lock a survécu à l\'opération.');
            self::assertTrue($this->probeCanLockTheRow($probe, 'cpg_user', $target->getId()), 'La ligne du compte administré est restée verrouillée.');
            self::assertFalse($this->em->getConnection()->isTransactionActive());
        } finally {
            $probe->close();
        }
    }

    /**
     * Issue #552 : la ligne a disparu entre son chargement et le verrou — le
     * perdant de deux `DELETE`, ou un compte que la purge vient d'emporter.
     * Introuvable, rien n'a tourné, et l'instance périmée est détachée et
     * refusée à la re-sauvegarde (#548).
     */
    public function testATargetDeletedMeanwhileIsANotFoundAndItsStaleInstanceIsForgotten(): void
    {
        $actor = $this->superAdmin('alpha');
        $target = $this->superAdmin('beta');
        $this->em->getConnection()->executeStatement('DELETE FROM cpg_user WHERE id = :id', ['id' => $target->getId()->toRfc4122()]);
        $ran = false;
        $caught = null;

        try {
            $this->lock()->whileAdministering($target, $actor, static function (CpgUser $locked) use (&$ran): void {
                $ran = true;
            });
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(CpgUserNotFoundException::class, $caught, \sprintf('Attendu introuvable, obtenu : %s.', get_debug_type($caught)));
        self::assertFalse($ran, 'L\'opération a tourné sur un compte disparu.');
        self::assertTrue($this->em->isOpen(), 'Un refus levé avant toute écriture a fermé l\'EntityManager.');
        self::assertFalse($this->em->getConnection()->isTransactionActive());
        self::assertFalse($this->em->contains($target), 'L\'instance périmée est restée gérée : find() la rendrait encore.');
        self::assertNull($this->em->find(CpgUser::class, $target->getId()));

        $this->expectException(VanishedEntitySavedAgainException::class);
        $this->em->persist($target);
    }

    /**
     * Issue #551 : l'acteur, autorisé par le pare-feu au début de la requête,
     * a été rétrogradé entre-temps par un autre super-administrateur. Relu
     * sous le verrou, il n'administre plus rien.
     */
    public function testAnActorDemotedMeanwhileIsRefusedBeforeTheOperation(): void
    {
        $actor = $this->superAdmin('alpha');
        $target = $this->superAdmin('beta');
        $this->em->getConnection()->executeStatement("UPDATE cpg_user SET roles = '[]' WHERE id = :id", ['id' => $actor->getId()->toRfc4122()]);
        $ran = false;
        $caught = null;

        try {
            $this->lock()->whileAdministering($target, $actor, static function (CpgUser $locked) use (&$ran): void {
                $ran = true;
            });
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(ActingAccountNoLongerSuperAdminException::class, $caught, \sprintf('Attendu un refus de l\'acteur, obtenu : %s.', get_debug_type($caught)));
        self::assertFalse($ran, 'L\'opération a tourné pour un acteur rétrogradé.');
        self::assertNotContains(CpgUser::ROLE_SUPER, $actor->getRoles(), 'L\'acteur n\'a pas été relu.');
        self::assertTrue($this->em->isOpen());
        self::assertFalse($this->em->getConnection()->isTransactionActive());
    }

    /**
     * L'acteur est relu avant la cible : révoqué, il reçoit le même refus que
     * sa cible existe ou non — relu après, un identifiant absent lui aurait
     * valu un 404, un présent un 403.
     */
    public function testAnActorDemotedMeanwhileIsRefusedEvenWhenTheTargetIsGone(): void
    {
        $actor = $this->superAdmin('alpha');
        $target = $this->superAdmin('beta');
        $this->em->getConnection()->executeStatement("UPDATE cpg_user SET roles = '[]' WHERE id = :id", ['id' => $actor->getId()->toRfc4122()]);
        $this->em->getConnection()->executeStatement('DELETE FROM cpg_user WHERE id = :id', ['id' => $target->getId()->toRfc4122()]);

        $this->expectException(ActingAccountNoLongerSuperAdminException::class);

        $this->lock()->whileAdministering($target, $actor, static fn (CpgUser $locked): null => null);
    }

    public function testAnActorDeletedMeanwhileIsRefusedBeforeTheOperation(): void
    {
        $actor = $this->superAdmin('alpha');
        $target = $this->superAdmin('beta');
        $this->em->getConnection()->executeStatement('DELETE FROM cpg_user WHERE id = :id', ['id' => $actor->getId()->toRfc4122()]);
        $ran = false;
        $caught = null;

        try {
            $this->lock()->whileAdministering($target, $actor, static function (CpgUser $locked) use (&$ran): void {
                $ran = true;
            });
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(ActingAccountNoLongerSuperAdminException::class, $caught, \sprintf('Attendu un refus de l\'acteur, obtenu : %s.', get_debug_type($caught)));
        self::assertFalse($ran, 'L\'opération a tourné pour un acteur supprimé.');
        self::assertFalse($this->em->contains($actor), 'L\'instance périmée de l\'acteur est restée gérée.');
        self::assertTrue($this->em->isOpen());
        self::assertFalse($this->em->getConnection()->isTransactionActive());
    }

    /**
     * Un refus levé par l'opération (le 409 du dernier ROLE_SUPER) est une
     * issue ordinaire : il annule la transaction, rend les deux verrous, sans
     * fermer l'EntityManager.
     */
    public function testARefusalInsideTheOperationLeavesTheEntityManagerOpenAndReleasesTheLocks(): void
    {
        $actor = $this->superAdmin('alpha');
        $target = $this->superAdmin('beta');
        $probe = self::openProbeBeside($this->em->getConnection());
        $caught = null;

        try {
            try {
                // Refus d'une cible ROLE_SUPER : celle-ci l'est.
                $this->lock()->whileAdministering($target, $actor, static function (CpgUser $locked): bool {
                    if (\in_array(CpgUser::ROLE_SUPER, $locked->getRoles(), true)) {
                        throw new LogicException('refus');
                    }

                    return true;
                });
                self::fail('L\'exception de l\'opération aurait dû remonter.');
            } catch (LogicException $exception) {
                $caught = $exception;
            }

            self::assertSame('refus', $caught->getMessage(), 'L\'exception de l\'opération aurait dû remonter telle quelle.');
            self::assertTrue($this->em->isOpen());
            self::assertFalse($this->em->getConnection()->isTransactionActive());
            self::assertTrue($this->administrationLockIsFree($probe), 'L\'advisory lock a survécu au refus.');
            self::assertTrue($this->probeCanLockTheRow($probe, 'cpg_user', $target->getId()), 'La ligne du compte administré est restée verrouillée après le refus.');
        } finally {
            $probe->close();
        }
    }

    /**
     * L'attente est bornée dans la transaction elle-même : la console n'a pas
     * le `lock_timeout` que FPM reçoit de `PGOPTIONS`.
     */
    public function testTheWaitIsBoundedInsideTheTransaction(): void
    {
        $this->em->getConnection()->executeStatement('SET lock_timeout = 0');
        $actor = $this->superAdmin('alpha');
        $target = $this->superAdmin('beta');

        $timeout = $this->lock()->whileAdministering($target, $actor, fn (CpgUser $locked): mixed => $this->em->getConnection()->fetchOne('SHOW lock_timeout'));

        self::assertSame('5s', $timeout);
    }

    /**
     * Au-delà du délai, une exception nommée — pas la `DriverException`
     * anonyme de PostgreSQL —, que ce soit l'advisory lock global…
     */
    public function testTheAdvisoryLockHeldBeyondTheTimeoutRaisesANamedException(): void
    {
        $actor = $this->superAdmin('alpha');
        $target = $this->superAdmin('beta');
        $holder = self::openProbeBeside($this->em->getConnection());
        $holder->beginTransaction();
        $holder->executeQuery(
            'SELECT pg_advisory_xact_lock(?, ?)',
            [PostgresAccountAdministrationLock::ADVISORY_NAMESPACE, PostgresAccountAdministrationLock::ADVISORY_KEY],
        );

        try {
            $this->expectTimeout($target, $actor, '/verrou global/');
        } finally {
            $holder->rollBack();
            $holder->close();
            $this->em->getConnection()->executeStatement('RESET statement_timeout');
            $this->em->getConnection()->executeStatement('RESET lock_timeout');
        }
    }

    /**
     * … ou la ligne du compte administré, tenue par un verrou de compte
     * (`PostgresAccountRowLock`, le handler d'invitation ou la purge). Chaque
     * attente sous son message : l'exploitant lit laquelle a dépassé.
     */
    public function testTheTargetRowHeldBeyondTheTimeoutRaisesANamedException(): void
    {
        $actor = $this->superAdmin('alpha');
        $target = $this->superAdmin('beta');
        $holder = self::openProbeBeside($this->em->getConnection());
        $holder->beginTransaction();
        $holder->fetchOne('SELECT 1 FROM cpg_user WHERE id = :id FOR UPDATE', ['id' => $target->getId()->toRfc4122()]);

        try {
            $this->expectTimeout($target, $actor, '/ligne du compte/');
        } finally {
            $holder->rollBack();
            $holder->close();
            $this->em->getConnection()->executeStatement('RESET statement_timeout');
            $this->em->getConnection()->executeStatement('RESET lock_timeout');
        }
    }

    /**
     * Issue #547 : le `flush()` de l'opération a réussi au point de
     * sauvegarde, puis la base a refusé le COMMIT. L'UnitOfWork tenait pour
     * écrit ce que le refus venait d'annuler : le squelette ferme
     * l'EntityManager, et une nouvelle lecture relit la base.
     */
    public function testACommitRefusedByTheDatabaseClosesTheEntityManagerOnItsRefusedWrites(): void
    {
        $actor = $this->superAdmin('alpha');
        $target = $this->superAdmin('beta');
        $connection = $this->em->getConnection();
        $caught = null;

        try {
            $this->lock()->whileAdministering($target, $actor, function (CpgUser $locked) use ($connection): null {
                $locked->setRoles([]);
                $this->em->flush();
                CommitRefusal::scheduleIn($connection);

                return null;
            });
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(DriverException::class, $caught, \sprintf('Le COMMIT refusé est remonté sous un autre nom : %s.', get_debug_type($caught)));
        self::assertSame(CommitRefusal::SQLSTATE, $caught->getSQLState());
        self::assertFalse($this->em->isOpen(), 'Un COMMIT refusé a laissé l\'EntityManager ouvert sur une écriture annulée.');
        self::assertFalse($connection->isTransactionActive());
        self::assertContains(CpgUser::ROLE_SUPER, $this->em->find(CpgUser::class, $target->getId())?->getRoles() ?? [], 'Une lecture après le refus rend les rôles que la base a refusés.');
    }

    /**
     * Issue #532 : dans une transaction déjà ouverte, le COMMIT du verrou ne
     * serait qu'un `RELEASE SAVEPOINT`, et le journal de sécurité, écrit au
     * retour, dirait validé ce que le COMMIT de l'appelant peut encore
     * annuler. Refusé avant de rien prendre, la transaction de l'appelant
     * intacte ; le message nomme le compte par son UUID, jamais par son
     * identifiant.
     */
    public function testTheLockRefusesToRunInsideATransactionAlreadyOpen(): void
    {
        $actor = $this->superAdmin('alpha');
        $target = $this->superAdmin('beta');
        $connection = $this->em->getConnection();
        $probe = self::openProbeBeside($connection);
        $connection->beginTransaction();
        $ran = false;
        $caught = null;

        try {
            $this->lock()->whileAdministering($target, $actor, static function (CpgUser $locked) use (&$ran): void {
                $ran = true;
            });
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        try {
            self::assertInstanceOf(AccountAdministrationLockInsideTransactionException::class, $caught, \sprintf("L'imbrication n'a pas été refusée sous son nom : %s.", get_debug_type($caught)));
            self::assertStringContainsString($target->getId()->toRfc4122(), $caught->getMessage());
            self::assertStringNotContainsString($target->getUserIdentifier(), $caught->getMessage(), "Le message cite l'identifiant du compte.");
            self::assertFalse($ran, "L'opération a tourné dans la transaction de l'appelant.");
            self::assertSame(1, $connection->getTransactionNestingLevel(), "La transaction de l'appelant a été touchée.");
            self::assertTrue($this->administrationLockIsFree($probe), 'L\'advisory lock a été pris malgré le refus.');
            self::assertTrue($this->probeCanLockTheRow($probe, 'cpg_user', $target->getId()), 'La ligne du compte a été verrouillée malgré le refus.');
        } finally {
            $connection->rollBack();
            $probe->close();
        }
    }

    public function testTheLockRefusesToNestInsideItself(): void
    {
        $actor = $this->superAdmin('alpha');
        $target = $this->superAdmin('beta');
        $probe = self::openProbeBeside($this->em->getConnection());
        $caught = null;

        try {
            try {
                $this->lock()->whileAdministering($target, $actor, fn (CpgUser $locked): mixed => $this->lock()->whileAdministering($locked, $actor, static fn (CpgUser $again): null => null));
            } catch (Throwable $exception) {
                $caught = $exception;
            }

            self::assertInstanceOf(AccountAdministrationLockInsideTransactionException::class, $caught, \sprintf("L'imbrication n'a pas été refusée sous son nom : %s.", get_debug_type($caught)));
            self::assertFalse($this->em->getConnection()->isTransactionActive());
            self::assertTrue($this->administrationLockIsFree($probe), 'L\'advisory lock du premier a survécu au refus.');
        } finally {
            $probe->close();
        }
    }

    /**
     * Session sans borne, comme la console, et un filet (issue #512) : si la
     * borne venait à manquer, l'attente échouerait en 57014 après 3 s au lieu
     * de pendre. C'est le délai de l'adaptateur qui doit s'appliquer.
     */
    private function expectTimeout(CpgUser $target, CpgUser $actor, string $messagePattern): void
    {
        $this->em->getConnection()->executeStatement('SET lock_timeout = 0');
        $this->em->getConnection()->executeStatement("SET statement_timeout = '3s'");

        $this->expectException(AccountAdministrationLockTimeoutException::class);
        $this->expectExceptionMessageMatches($messagePattern);
        (new PostgresAccountAdministrationLock($this->em, new NullLogger(), self::getContainer()->get(RereadUnderLock::class), '50ms'))
            ->whileAdministering($target, $actor, static fn (CpgUser $locked): null => null);
    }

    private function lock(): AccountAdministrationLockInterface
    {
        return self::getContainer()->get(AccountAdministrationLockInterface::class);
    }

    private function superAdmin(string $username): CpgUser
    {
        $user = new CpgUser($username, 'hashed-password');
        $user->setRoles([CpgUser::ROLE_SUPER]);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * L'advisory lock des administrations, tenté depuis `$probe`.
     */
    private function administrationLockIsFree(Connection $probe): bool
    {
        return $this->probeCanTakeTheAdvisoryLock($probe, PostgresAccountAdministrationLock::ADVISORY_NAMESPACE, PostgresAccountAdministrationLock::ADVISORY_KEY);
    }

}
