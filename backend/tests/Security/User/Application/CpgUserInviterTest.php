<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Application;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\User\Application\CpgUserInviter;
use App\Security\User\Application\Message\SendAccountInvitationMessage;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\AccountNotAwaitingActivationException;
use App\Security\User\Domain\Exception\EmailAlreadyUsedException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Security\User\Domain\Service\UsernameGenerator;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Depuis l'audit C2 (décision D3), CpgUserInviter ne crée plus de jeton : il
 * crée / marque le compte en attente d'activation et publie
 * SendAccountInvitationMessage { userId, locale }. La création du
 * PasswordSetupToken et l'envoi de l'e-mail sont couverts par
 * SendAccountInvitationHandlerTest.
 */
final class CpgUserInviterTest extends TestCase
{
    private const int GENERATED_ID = 123;

    public function testInviteCreatesAPendingUserWithADerivedUsernameAndAnInvitationMessage(): void
    {
        $clock = new MockClock('2026-09-03 12:00:00');

        $cpgUserRepository = $this->createMock(CpgUserRepositoryInterface::class);
        $cpgUserRepository->method('findOneByEmail')->willReturn(null);
        $cpgUserRepository->method('findOneByUsername')->willReturn(null);
        $cpgUserRepository->expects(self::once())->method('save')->willReturnCallback(
            static function (CpgUser $user): void {
                // Simule l'identifiant généré par Doctrine au flush.
                (new \ReflectionProperty(CpgUser::class, 'id'))->setValue($user, self::GENERATED_ID);
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
            $messageBus,
            $clock,
        );

        $user = $inviter->invite('jean.dupont@example.com', Locale::FR);

        self::assertSame('jean.dupont', $user->getUsername());
        self::assertSame('jean.dupont@example.com', $user->getEmail());
        self::assertTrue($user->isPendingActivation());
        self::assertSame(['ROLE_USER'], $user->getRoles());

        self::assertInstanceOf(SendAccountInvitationMessage::class, $dispatched);
        self::assertSame(self::GENERATED_ID, $dispatched->userId);
        self::assertSame('fr', $dispatched->locale);
    }

    public function testInviteRejectsAnAlreadyUsedEmailWithoutCreatingAnything(): void
    {
        $cpgUserRepository = $this->createMock(CpgUserRepositoryInterface::class);
        $cpgUserRepository->method('findOneByEmail')->willReturn(new CpgUser('existing', 'hashed-password'));
        $cpgUserRepository->expects(self::never())->method('save');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $inviter = new CpgUserInviter(
            $cpgUserRepository,
            new UsernameGenerator($cpgUserRepository),
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

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $inviter = new CpgUserInviter(
            $cpgUserRepository,
            new UsernameGenerator($cpgUserRepository),
            $messageBus,
            new MockClock('2026-09-03 12:00:00'),
        );

        $this->expectException(EmailAlreadyUsedException::class);

        $inviter->invite('race@example.com', Locale::FR);
    }

    public function testReinviteRedispatchesForAPendingAccount(): void
    {
        $user = new CpgUser('newcomer', '');
        $user->setEmail('newcomer@example.com');
        $user->markInvited(new \DateTimeImmutable('2026-09-01 09:00:00'));
        (new \ReflectionProperty(CpgUser::class, 'id'))->setValue($user, self::GENERATED_ID);

        $cpgUserRepository = self::createStub(CpgUserRepositoryInterface::class);

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
            $messageBus,
            new MockClock(),
        );

        $inviter->reinvite($user, Locale::EN);

        self::assertInstanceOf(SendAccountInvitationMessage::class, $dispatched);
        self::assertSame(self::GENERATED_ID, $dispatched->userId);
        self::assertSame('en', $dispatched->locale);
    }

    public function testReinviteRejectsAnAlreadyActivatedAccount(): void
    {
        $user = new CpgUser('active', 'hashed-password');
        $user->setEmail('active@example.com');
        $user->markInvited(new \DateTimeImmutable('2026-09-01 09:00:00'));
        $user->markActivated(new \DateTimeImmutable('2026-09-02 10:00:00'));

        $cpgUserRepository = self::createStub(CpgUserRepositoryInterface::class);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $inviter = new CpgUserInviter(
            $cpgUserRepository,
            new UsernameGenerator($cpgUserRepository),
            $messageBus,
            new MockClock(),
        );

        $this->expectException(AccountNotAwaitingActivationException::class);

        $inviter->reinvite($user, Locale::FR);
    }
}
