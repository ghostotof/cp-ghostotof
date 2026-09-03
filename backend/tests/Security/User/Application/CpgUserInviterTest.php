<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Application;

use App\Security\User\Application\CpgUserInviter;
use App\Security\User\Application\Message\SendAccountInvitationMessage;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Entity\PasswordSetupToken;
use App\Security\User\Domain\Exception\EmailAlreadyUsedException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Security\User\Domain\Repository\PasswordSetupTokenRepositoryInterface;
use App\Security\User\Domain\Service\UsernameGenerator;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class CpgUserInviterTest extends TestCase
{
    public function testInviteCreatesAPendingUserWithADerivedUsernameATokenAndAnInvitationMessage(): void
    {
        $clock = new MockClock('2026-09-03 12:00:00');

        $cpgUserRepository = $this->createMock(CpgUserRepositoryInterface::class);
        $cpgUserRepository->method('findOneByEmail')->willReturn(null);
        $cpgUserRepository->method('findOneByUsername')->willReturn(null);
        $cpgUserRepository->expects(self::once())->method('save')->with(self::isInstanceOf(CpgUser::class));

        $savedToken = null;
        $tokenRepository = $this->createMock(PasswordSetupTokenRepositoryInterface::class);
        $tokenRepository->expects(self::once())->method('deleteForUser');
        $tokenRepository->expects(self::once())->method('save')->willReturnCallback(
            static function (PasswordSetupToken $token) use (&$savedToken): void {
                $savedToken = $token;
            },
        );

        $dispatched = null;
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched): Envelope {
                $dispatched = $message;

                return new Envelope($message);
            },
        );

        $inviter = new CpgUserInviter(
            $cpgUserRepository,
            new UsernameGenerator($cpgUserRepository),
            $tokenRepository,
            $messageBus,
            $clock,
        );

        $user = $inviter->invite('jean.dupont@example.com', Locale::FR);

        self::assertSame('jean.dupont', $user->getUsername());
        self::assertSame('jean.dupont@example.com', $user->getEmail());
        self::assertTrue($user->isPendingActivation());
        self::assertSame(['ROLE_USER'], $user->getRoles());

        self::assertInstanceOf(PasswordSetupToken::class, $savedToken);
        self::assertSame(64, \strlen($savedToken->getTokenHash()));
        self::assertSame('2026-09-05 12:00:00', $savedToken->getExpiresAt()->format('Y-m-d H:i:s'));

        self::assertInstanceOf(SendAccountInvitationMessage::class, $dispatched);
        self::assertSame('jean.dupont@example.com', $dispatched->recipientEmail);
        self::assertSame('jean.dupont', $dispatched->username);
        self::assertSame('fr', $dispatched->locale);
        self::assertSame(1, preg_match('/^[0-9a-f]{64}$/', $dispatched->clearToken));
        // Le hash stocké est bien le SHA-256 du jeton en clair transmis par e-mail.
        self::assertSame($savedToken->getTokenHash(), hash('sha256', $dispatched->clearToken));
    }

    public function testInviteRejectsAnAlreadyUsedEmailWithoutCreatingAnything(): void
    {
        $cpgUserRepository = $this->createMock(CpgUserRepositoryInterface::class);
        $cpgUserRepository->method('findOneByEmail')->willReturn(new CpgUser('existing', 'hashed-password'));
        $cpgUserRepository->expects(self::never())->method('save');

        $tokenRepository = $this->createMock(PasswordSetupTokenRepositoryInterface::class);
        $tokenRepository->expects(self::never())->method('save');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $inviter = new CpgUserInviter(
            $cpgUserRepository,
            new UsernameGenerator($cpgUserRepository),
            $tokenRepository,
            $messageBus,
            new MockClock(),
        );

        $this->expectException(EmailAlreadyUsedException::class);

        $inviter->invite('taken@example.com', Locale::FR);
    }

    public function testInviteMapsAConcurrentUniqueViolationToEmailAlreadyUsed(): void
    {
        // La pré-vérification passe, mais un save() concurrent a déjà inséré la
        // ligne : la contrainte unique en base lève, et on doit répondre 409,
        // pas 500. Rien ne doit être dispatché.
        $cpgUserRepository = self::createStub(CpgUserRepositoryInterface::class);
        $cpgUserRepository->method('findOneByEmail')->willReturn(null);
        $cpgUserRepository->method('findOneByUsername')->willReturn(null);
        $cpgUserRepository->method('save')->willThrowException(
            new UniqueConstraintViolationException(self::createStub(DriverException::class), null),
        );

        $tokenRepository = $this->createMock(PasswordSetupTokenRepositoryInterface::class);
        $tokenRepository->expects(self::never())->method('save');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $inviter = new CpgUserInviter(
            $cpgUserRepository,
            new UsernameGenerator($cpgUserRepository),
            $tokenRepository,
            $messageBus,
            new MockClock('2026-09-03 12:00:00'),
        );

        $this->expectException(EmailAlreadyUsedException::class);

        $inviter->invite('race@example.com', Locale::FR);
    }
}
