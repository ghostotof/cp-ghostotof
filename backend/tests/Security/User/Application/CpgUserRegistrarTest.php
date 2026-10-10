<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Application;

use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\User\Application\CpgUserRegistrar;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\UsernameAlreadyUsedException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CpgUserRegistrarTest extends TestCase
{
    public function testRegisterHashesPasswordAndPersistsUser(): void
    {
        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findOneByUsername')
            ->with('jane')
            ->willReturn(null);
        $repository->expects(self::once())
            ->method('save')
            ->with(self::isInstanceOf(CpgUser::class));

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects(self::once())
            ->method('hashPassword')
            ->with(self::isInstanceOf(CpgUser::class), 'plain-password')
            ->willReturn('hashed-password');

        $registrar = new CpgUserRegistrar($repository, $hasher, self::createStub(SecurityAuditLoggerInterface::class));

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

        $registrar = new CpgUserRegistrar($repository, $hasher, self::createStub(SecurityAuditLoggerInterface::class));

        $user = $registrar->register('super', 'plain-password', [CpgUser::ROLE_SUPER]);

        self::assertSame([CpgUser::ROLE_SUPER, 'ROLE_USER'], $user->getRoles());
    }

    public function testRegisterThrowsWhenUsernameAlreadyUsed(): void
    {
        $existingUser = new CpgUser('jane', 'hashed-password');

        $repository = $this->createMock(CpgUserRepositoryInterface::class);
        $repository->expects(self::once())->method('findOneByUsername')->with('jane')->willReturn($existingUser);
        $repository->expects(self::never())->method('save');

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects(self::never())->method('hashPassword');

        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('userCreated');

        $registrar = new CpgUserRegistrar($repository, $hasher, $auditLogger);

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
        $repository->method('save')->willReturnCallback(static function () use (&$saved): void {
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

        new CpgUserRegistrar($repository, $hasher, $auditLogger)->register('super', 'plain-password', [CpgUser::ROLE_SUPER]);
    }

    /** La contrainte unique a le dernier mot : une course perdue n'écrit rien non plus. */
    public function testRegisterRecordsNothingWhenTheUniqueConstraintRefusesTheSave(): void
    {
        $repository = self::createStub(CpgUserRepositoryInterface::class);
        $repository->method('findOneByUsername')->willReturn(null);
        $repository->method('save')->willThrowException(self::createStub(UniqueConstraintViolationException::class));

        $hasher = self::createStub(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed-password');

        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('userCreated');

        $this->expectException(UsernameAlreadyUsedException::class);

        new CpgUserRegistrar($repository, $hasher, $auditLogger)->register('jane', 'plain-password');
    }
}
