<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Application;

use App\Security\User\Application\PasswordSetupService;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Entity\PasswordSetupToken;
use App\Security\User\Domain\Exception\InvalidPasswordSetupTokenException;
use App\Security\User\Domain\Exception\PasswordSetupTokenExpiredException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Security\User\Domain\Repository\PasswordSetupTokenRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PasswordSetupServiceTest extends TestCase
{
    private const string CLEAR_TOKEN = 'a-clear-token-value';

    public function testValidateLooksUpTheTokenByItsSha256Hash(): void
    {
        $clock = new MockClock('2026-09-10 10:00:00');
        $tokenRepository = $this->createMock(PasswordSetupTokenRepositoryInterface::class);
        $tokenRepository->expects(self::once())
            ->method('findOneByTokenHash')
            ->with(hash('sha256', self::CLEAR_TOKEN))
            ->willReturn($this->usableToken($clock));

        $this->service($tokenRepository, self::createStub(CpgUserRepositoryInterface::class), self::createStub(UserPasswordHasherInterface::class), $clock)
            ->validate(self::CLEAR_TOKEN);
    }

    public function testValidateRejectsAnUnknownToken(): void
    {
        $tokenRepository = self::createStub(PasswordSetupTokenRepositoryInterface::class);
        $tokenRepository->method('findOneByTokenHash')->willReturn(null);

        $this->expectException(InvalidPasswordSetupTokenException::class);

        $this->service($tokenRepository)->validate(self::CLEAR_TOKEN);
    }

    public function testValidateRejectsAnExpiredToken(): void
    {
        $clock = new MockClock('2026-09-10 10:00:00');
        $expired = new PasswordSetupToken(
            new CpgUser('jane', ''),
            hash('sha256', self::CLEAR_TOKEN),
            new \DateTimeImmutable('2026-09-09 10:00:00'),
        );
        $tokenRepository = self::createStub(PasswordSetupTokenRepositoryInterface::class);
        $tokenRepository->method('findOneByTokenHash')->willReturn($expired);

        $this->expectException(PasswordSetupTokenExpiredException::class);

        $this->service($tokenRepository, clock: $clock)->validate(self::CLEAR_TOKEN);
    }

    public function testValidateRejectsAnAlreadyUsedToken(): void
    {
        $clock = new MockClock('2026-09-10 10:00:00');
        $used = $this->usableToken($clock);
        $used->markUsed(new \DateTimeImmutable('2026-09-10 09:00:00'));
        $tokenRepository = self::createStub(PasswordSetupTokenRepositoryInterface::class);
        $tokenRepository->method('findOneByTokenHash')->willReturn($used);

        $this->expectException(PasswordSetupTokenExpiredException::class);

        $this->service($tokenRepository, clock: $clock)->validate(self::CLEAR_TOKEN);
    }

    public function testCompleteHashesThePasswordActivatesTheUserAndConsumesTheToken(): void
    {
        $clock = new MockClock('2026-09-10 10:00:00');
        $user = new CpgUser('jane', '');
        $user->markInvited(new \DateTimeImmutable('2026-09-08 08:00:00'));
        $token = $this->tokenFor($user, $clock);

        $tokenRepository = $this->createMock(PasswordSetupTokenRepositoryInterface::class);
        $tokenRepository->method('findOneByTokenHash')->willReturn($token);
        $tokenRepository->expects(self::once())->method('save')->with($token);

        $cpgUserRepository = $this->createMock(CpgUserRepositoryInterface::class);
        $cpgUserRepository->expects(self::once())->method('save')->with($user);

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects(self::once())
            ->method('hashPassword')
            ->with($user, 'NewSecurePass1')
            ->willReturn('hashed-new-password');

        $this->service($tokenRepository, $cpgUserRepository, $hasher, $clock)
            ->complete(self::CLEAR_TOKEN, 'NewSecurePass1');

        self::assertSame('hashed-new-password', $user->getPassword());
        self::assertFalse($user->isPendingActivation());
        self::assertSame('2026-09-10 10:00:00', $user->getActivatedAt()?->format('Y-m-d H:i:s'));
        self::assertFalse($token->isUsable($clock->now()));
        self::assertSame('2026-09-10 10:00:00', $token->getUsedAt()?->format('Y-m-d H:i:s'));
    }

    public function testCompleteRejectsAnExpiredTokenWithoutTouchingAnything(): void
    {
        $clock = new MockClock('2026-09-10 10:00:00');
        $expired = new PasswordSetupToken(
            new CpgUser('jane', ''),
            hash('sha256', self::CLEAR_TOKEN),
            new \DateTimeImmutable('2026-09-01 10:00:00'),
        );

        $tokenRepository = $this->createMock(PasswordSetupTokenRepositoryInterface::class);
        $tokenRepository->method('findOneByTokenHash')->willReturn($expired);
        $tokenRepository->expects(self::never())->method('save');

        $cpgUserRepository = $this->createMock(CpgUserRepositoryInterface::class);
        $cpgUserRepository->expects(self::never())->method('save');

        $this->expectException(PasswordSetupTokenExpiredException::class);

        $this->service($tokenRepository, $cpgUserRepository, clock: $clock)
            ->complete(self::CLEAR_TOKEN, 'NewSecurePass1');
    }

    private function usableToken(MockClock $clock): PasswordSetupToken
    {
        return $this->tokenFor(new CpgUser('jane', ''), $clock);
    }

    private function tokenFor(CpgUser $user, MockClock $clock): PasswordSetupToken
    {
        return new PasswordSetupToken($user, hash('sha256', self::CLEAR_TOKEN), $clock->now()->modify('+1 day'));
    }

    private function service(
        PasswordSetupTokenRepositoryInterface $tokenRepository,
        ?CpgUserRepositoryInterface $cpgUserRepository = null,
        ?UserPasswordHasherInterface $hasher = null,
        ?MockClock $clock = null,
    ): PasswordSetupService {
        return new PasswordSetupService(
            $tokenRepository,
            $cpgUserRepository ?? self::createStub(CpgUserRepositoryInterface::class),
            $hasher ?? self::createStub(UserPasswordHasherInterface::class),
            $clock ?? new MockClock(),
        );
    }
}
