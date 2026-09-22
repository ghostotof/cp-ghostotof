<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Application;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\User\Application\CpgUserInviter;
use App\Security\User\Application\Message\SendAccountInvitationMessage;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\AccountNotAwaitingActivationException;
use App\Security\User\Domain\Exception\EmailAlreadyUsedException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Security\User\Domain\Service\UsernameGenerator;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\MockObject\MockObject;
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
    public function testInviteCreatesAPendingUserWithADerivedUsernameAndAnInvitationMessage(): void
    {
        $clock = new MockClock('2026-09-03 12:00:00');

        $cpgUserRepository = $this->createMock(CpgUserRepositoryInterface::class);
        $cpgUserRepository->method('findOneByEmail')->willReturn(null);
        $cpgUserRepository->method('findOneByUsername')->willReturn(null);
        // Plus rien à simuler au save() : depuis la spec 0003 l'identité est
        // posée par le constructeur de l'entité, donc connue avant la
        // persistance — c'est précisément ce que ce style rend possible.
        $cpgUserRepository->expects(self::once())->method('save');

        $dispatched = null;
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched): Envelope {
                $dispatched = $message;

                return new Envelope($message);
            },
        );

        // Journal de sécurité (D5) : une ligne `user-invited` pour le compte
        // créé, après le save() et le dispatch — jamais avant.
        $logged = null;
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('userInvited')->willReturnCallback(
            static function (CpgUser $user) use (&$logged): void {
                $logged = $user;
            },
        );

        $inviter = new CpgUserInviter(
            $cpgUserRepository,
            new UsernameGenerator($cpgUserRepository),
            $messageBus,
            $clock,
            $auditLogger,
        );

        $user = $inviter->invite('jean.dupont@example.com', Locale::FR);

        self::assertSame($user, $logged);

        self::assertSame('jean.dupont', $user->getUsername());
        self::assertSame('jean.dupont@example.com', $user->getEmail());
        self::assertTrue($user->isPendingActivation());
        // ADR 0003 D1 : l'invitation par un ROLE_SUPER *est* l'octroi
        // nominatif de ROLE_TRUSTED, pas un simple compte au palier de base.
        self::assertSame([CpgUser::ROLE_TRUSTED, 'ROLE_USER'], $user->getRoles());

        self::assertInstanceOf(SendAccountInvitationMessage::class, $dispatched);
        // Chaîne RFC 4122 (spec 0003 D7) : le message reste lisible et
        // rejouable sans dépendre de la sérialisation d'un objet.
        self::assertSame($user->getId()->toRfc4122(), $dispatched->userId);
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
            $this->auditLoggerExpectingNothing(),
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
            $this->auditLoggerExpectingNothing(),
        );

        $this->expectException(EmailAlreadyUsedException::class);

        $inviter->invite('race@example.com', Locale::FR);
    }

    /**
     * Round de correction (C1) : la relance doit repousser le délai de purge
     * (issue #238) exactement comme la première invitation, en remettant
     * `invitedAt` à l'instant de l'horloge et en sauvegardant *avant* de
     * redispatcher — sinon le seuil de 30 jours court toujours depuis la
     * toute première invitation.
     */
    public function testReinviteResetsInvitedAtToNowAndSavesBeforeDispatching(): void
    {
        $clock = new MockClock('2026-09-22 08:00:00');
        $user = new CpgUser('newcomer', '');
        $user->setEmail('newcomer@example.com');
        $user->markInvited(new \DateTimeImmutable('2026-09-01 09:00:00'));

        $cpgUserRepository = $this->createMock(CpgUserRepositoryInterface::class);
        $cpgUserRepository->expects(self::once())->method('save')->with($user);

        $dispatched = null;
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched): Envelope {
                $dispatched = $message;

                return new Envelope($message);
            },
        );

        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('userReinvited')->with($user);

        $inviter = new CpgUserInviter(
            $cpgUserRepository,
            new UsernameGenerator($cpgUserRepository),
            $messageBus,
            $clock,
            $auditLogger,
        );

        $inviter->reinvite($user, Locale::EN);

        self::assertEquals($clock->now(), $user->getInvitedAt());
        self::assertInstanceOf(SendAccountInvitationMessage::class, $dispatched);
        self::assertSame($user->getId()->toRfc4122(), $dispatched->userId);
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
            $this->auditLoggerExpectingNothing(),
        );

        $this->expectException(AccountNotAwaitingActivationException::class);

        $inviter->reinvite($user, Locale::FR);
    }

    /**
     * Sur un refus, rien n'a eu lieu : le journal ne doit rien dire.
     */
    private function auditLoggerExpectingNothing(): SecurityAuditLoggerInterface&MockObject
    {
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('userInvited');
        $auditLogger->expects(self::never())->method('userReinvited');

        return $auditLogger;
    }
}
