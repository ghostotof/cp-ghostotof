<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Infrastructure\Doctrine;

use App\Security\User\Application\AccountLockInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\CpgUserNotFoundException;
use App\Security\User\Infrastructure\Doctrine\AccountLockTimeoutException;
use App\Security\User\Infrastructure\Doctrine\PostgresAccountRowLock;
use App\Tests\Support\OpensProbeConnection;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le verrou du compte sur la base réelle (issues #435, #458). Sa raison d'être,
 * la sérialisation de deux processus, est couverte par
 * SendAccountInvitationConcurrencyTest et PasswordSetupActivationConcurrencyTest.
 */
final class PostgresAccountRowLockTest extends KernelTestCase
{
    use OpensProbeConnection;

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
        $this->em->getConnection()->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    public function testTheOperationSeesTheAccountAsTheLockReadsIt(): void
    {
        $user = $this->pendingUser();
        $this->em->getConnection()->executeStatement('UPDATE cpg_user SET activated_at = now() WHERE id = :id', ['id' => $user->getId()->toRfc4122()]);

        $pending = $this->lock()->whileLocked($user, static fn (CpgUser $locked): bool => $locked->isPendingActivation());

        self::assertFalse($pending, 'L\'opération a lu l\'entité d\'avant le verrou.');
    }

    public function testAnAccountDeletedMeanwhileIsANotFound(): void
    {
        $user = $this->pendingUser();
        $this->em->getConnection()->executeStatement('DELETE FROM cpg_user WHERE id = :id', ['id' => $user->getId()->toRfc4122()]);

        $this->expectException(CpgUserNotFoundException::class);

        $this->lock()->whileLocked($user, static fn (CpgUser $locked): null => null);
    }

    /**
     * Un refus levé par l'opération (un lien déjà utilisé, 404) est une issue
     * ordinaire : il annule la transaction sans fermer l'EntityManager.
     */
    public function testARefusalInsideTheOperationLeavesTheEntityManagerOpen(): void
    {
        $user = $this->pendingUser();

        $refusal = null;
        try {
            // Refus d'un compte en attente : celui-ci l'est.
            $this->lock()->whileLocked($user, static function (CpgUser $locked): bool {
                if ($locked->isPendingActivation()) {
                    throw new LogicException('refus');
                }

                return true;
            });
        } catch (LogicException $exception) {
            $refusal = $exception;
        }

        self::assertInstanceOf(LogicException::class, $refusal, 'L\'exception de l\'opération aurait dû remonter.');

        self::assertTrue($this->em->isOpen());
        self::assertFalse($this->em->getConnection()->isTransactionActive());
    }

    /**
     * L'attente est bornée dans la transaction elle-même : la console (le
     * worker) n'a pas le `lock_timeout` que FPM reçoit de `PGOPTIONS`.
     */
    public function testTheWaitIsBoundedInsideTheTransaction(): void
    {
        $user = $this->pendingUser();

        $timeout = $this->lock()->whileLocked($user, fn (CpgUser $locked): mixed => $this->em->getConnection()->fetchOne('SHOW lock_timeout'));

        self::assertSame('5s', $timeout);
    }

    /**
     * Au-delà du délai, une exception nommée — pas la `DriverException`
     * anonyme de PostgreSQL —, comme le verrou de périmètre d'ordre (#389).
     */
    public function testALockHeldBeyondTheTimeoutRaisesANamedException(): void
    {
        $user = $this->pendingUser();
        $holder = self::openProbeBeside($this->em->getConnection());
        $holder->beginTransaction();
        $holder->fetchOne('SELECT 1 FROM cpg_user WHERE id = :id FOR UPDATE', ['id' => $user->getId()->toRfc4122()]);

        try {
            $this->expectException(AccountLockTimeoutException::class);
            (new PostgresAccountRowLock($this->em, '50ms'))->whileLocked($user, static fn (CpgUser $locked): null => null);
        } finally {
            $holder->rollBack();
            $holder->close();
        }
    }

    private function lock(): AccountLockInterface
    {
        return self::getContainer()->get(AccountLockInterface::class);
    }

    private function pendingUser(): CpgUser
    {
        $user = new CpgUser('newcomer', '');
        $user->setEmail('newcomer@example.com');
        $user->markInvited(new DateTimeImmutable('-1 hour'));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
