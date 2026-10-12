<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Application;

use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\User\Application\CpgUserRegistrar;
use App\Security\User\Application\UsernameAllocationLockInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\UsernameAlreadyUsedException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use Closure;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CpgUserRegistrarTest extends TestCase
{
    public function testRegisterHashesPasswordAndPersistsUser(): void
    {
        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        // Deux fois : le refus rapide avant le hachage, puis la vérification
        // qui fait foi, sous le verrou (issue #364).
        $repository->expects(self::exactly(2))
            ->method('findOneByUsername')
            ->with('jane')
            ->willReturn(null);
        $repository->expects(self::once())
            ->method('add')
            ->with(self::isInstanceOf(CpgUser::class));

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects(self::once())
            ->method('hashPassword')
            ->with(self::isInstanceOf(CpgUser::class), 'plain-password')
            ->willReturn('hashed-password');

        $registrar = new CpgUserRegistrar($repository, $hasher, self::createStub(SecurityAuditLoggerInterface::class), $this->lockRunningTheOperation());

        $user = $registrar->register('jane', 'plain-password');

        self::assertSame('jane', $user->getUsername());
        self::assertSame('hashed-password', $user->getPassword());
        self::assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testRegisterAssignsGivenRoles(): void
    {
        $repository = self::createStub(CpgUserRepositoryInterface::class);
        $repository->method('findOneByUsername')->willReturn(null);

        $hasher = self::createStub(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed-password');

        $registrar = new CpgUserRegistrar($repository, $hasher, self::createStub(SecurityAuditLoggerInterface::class), $this->lockRunningTheOperation());

        $user = $registrar->register('super', 'plain-password', [CpgUser::ROLE_SUPER]);

        self::assertSame([CpgUser::ROLE_SUPER, 'ROLE_USER'], $user->getRoles());
    }

    public function testRegisterThrowsWhenUsernameAlreadyUsed(): void
    {
        $existingUser = new CpgUser('jane', 'hashed-password');

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneByUsername')->with('jane')->willReturn($existingUser);
        $repository->expects(self::never())->method('add');

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects(self::never())->method('hashPassword');

        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('userCreated');

        $registrar = new CpgUserRegistrar($repository, $hasher, $auditLogger, $this->lockRunningTheOperation());

        $this->expectException(UsernameAlreadyUsedException::class);

        $registrar->register('jane', 'plain-password');
    }

    /**
     * Issue #386 : la création CLI, ROLE_SUPER compris, laisse sa trace dans
     * `security_audit` — après l'enregistrement, comme les autres cas
     * d'usage : une création refusée n'écrit rien.
     */
    public function testRegisterRecordsTheCreationAfterSavingIt(): void
    {
        $saved = false;
        $repository = self::createStub(CpgUserRepositoryInterface::class);
        $repository->method('findOneByUsername')->willReturn(null);
        $repository->method('add')->willReturnCallback(static function () use (&$saved): void {
            $saved = true;
        });

        $hasher = self::createStub(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed-password');

        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('userCreated')
            ->with(self::callback(static function (CpgUser $user) use (&$saved): bool {
                self::assertTrue($saved, 'La trace suit l\'enregistrement, jamais ne le précède.');

                return 'super' === $user->getUsername();
            }));

        new CpgUserRegistrar($repository, $hasher, $auditLogger, $this->lockRunningTheOperation())->register('super', 'plain-password', [CpgUser::ROLE_SUPER]);
    }

    /** La contrainte unique a le dernier mot : une course perdue n'écrit rien non plus. */
    public function testRegisterRecordsNothingWhenTheUniqueConstraintRefusesTheSave(): void
    {
        $repository = self::createStub(CpgUserRepositoryInterface::class);
        $repository->method('findOneByUsername')->willReturn(null);
        $repository->method('add')->willThrowException(UsernameAlreadyUsedException::takenMeanwhile());

        $hasher = self::createStub(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed-password');

        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('userCreated');

        $this->expectException(UsernameAlreadyUsedException::class);

        new CpgUserRegistrar($repository, $hasher, $auditLogger, $this->lockRunningTheOperation())->register('jane', 'plain-password');
    }

    /**
     * Issue #364 : la vérification qui fait foi et l'enregistrement ont lieu
     * sous le verrou que prennent les invitations ; le hachage, lent, reste
     * dehors pour ne pas les faire attendre.
     */
    public function testRegisterChecksAndAddsUnderTheAllocationLockAndHashesOutsideIt(): void
    {
        $trace = new class implements UsernameAllocationLockInterface {
            /** @var list<string> */
            public array $events = [];

            private bool $locked = false;

            public function whileAllocating(Closure $operation): mixed
            {
                $this->locked = true;
                try {
                    return $operation();
                } finally {
                    $this->locked = false;
                }
            }

            public function record(string $call): void
            {
                $this->events[] = $call.($this->locked ? ' (verrou)' : '');
            }
        };

        $repository = self::createStub(CpgUserRepositoryInterface::class);
        $repository->method('findOneByUsername')->willReturnCallback(static function () use ($trace): null {
            $trace->record('findOneByUsername');

            return null;
        });
        $repository->method('add')->willReturnCallback(static function () use ($trace): void {
            $trace->record('add');
        });
        $hasher = self::createStub(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturnCallback(static function () use ($trace): string {
            $trace->record('hashPassword');

            return 'hashed-password';
        });

        new CpgUserRegistrar($repository, $hasher, self::createStub(SecurityAuditLoggerInterface::class), $trace)->register('jane', 'plain-password');

        self::assertSame(['findOneByUsername', 'hashPassword', 'findOneByUsername (verrou)', 'add (verrou)'], $trace->events);
    }

    private function lockRunningTheOperation(): UsernameAllocationLockInterface
    {
        return new class implements UsernameAllocationLockInterface {
            public function whileAllocating(Closure $operation): mixed
            {
                return $operation();
            }
        };
    }
}
