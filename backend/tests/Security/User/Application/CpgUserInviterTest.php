<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Application;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\User\Application\CpgUserInviter;
use App\Security\User\Application\Message\SendAccountInvitationMessage;
use App\Security\User\Application\UsernameAllocationLockInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\AccountNotAwaitingActivationException;
use App\Security\User\Domain\Exception\EmailAlreadyUsedException;
use App\Security\User\Domain\Exception\InvitationQueueUnavailableException;
use App\Security\User\Domain\Exception\UsernameAlreadyUsedException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Security\User\Domain\Service\UsernameGenerator;
use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * Depuis l'audit C2 (décision D3), CpgUserInviter ne crée plus de jeton : il
 * crée / marque le compte en attente d'activation et publie
 * SendAccountInvitationMessage { userId, locale, invitationGeneration }. La création du
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
        $cpgUserRepository->expects(self::once())->method('add');

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
            $this->lockRunningTheOperation(),
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
        // La génération de l'invitation qu'il sert (issue #434) : la première.
        self::assertSame(1, $dispatched->invitationGeneration);
    }

    public function testInviteRejectsAnAlreadyUsedEmailWithoutCreatingAnything(): void
    {
        $cpgUserRepository = $this->createMock(CpgUserRepositoryInterface::class);
        $cpgUserRepository->method('findOneByEmail')->willReturn(new CpgUser('existing', 'hashed-password'));
        $cpgUserRepository->expects(self::never())->method('add');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $inviter = new CpgUserInviter(
            $cpgUserRepository,
            new UsernameGenerator($cpgUserRepository),
            $messageBus,
            new MockClock(),
            $this->auditLoggerExpectingNothing(),
            $this->lockRunningTheOperation(),
        );

        $this->expectException(EmailAlreadyUsedException::class);

        $inviter->invite('taken@example.com', Locale::FR);
    }

    /**
     * La pré-vérification passe, mais une écriture hors verrou a déjà pris
     * l'adresse ou l'identifiant : add() nomme l'unicité violée (issue #364,
     * traduction couverte par CpgUserUniqueViolationTest), et le refus remonte
     * tel quel. Rien ne doit être dispatché ni journalisé.
     *
     * @param class-string<Throwable> $expected
     */
    #[DataProvider('refusalsOfAdd')]
    public function testInvitePassesOnTheRefusalOfAddWithoutDispatching(Throwable $refusal, string $expected): void
    {
        $cpgUserRepository = self::createStub(CpgUserRepositoryInterface::class);
        $cpgUserRepository->method('findOneByEmail')->willReturn(null);
        $cpgUserRepository->method('findOneByUsername')->willReturn(null);
        $cpgUserRepository->method('add')->willThrowException($refusal);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $inviter = new CpgUserInviter(
            $cpgUserRepository,
            new UsernameGenerator($cpgUserRepository),
            $messageBus,
            new MockClock('2026-09-03 12:00:00'),
            $this->auditLoggerExpectingNothing(),
            $this->lockRunningTheOperation(),
        );

        $this->expectException($expected);

        $inviter->invite('race@example.com', Locale::FR);
    }

    /**
     * @return iterable<string, array{Throwable, class-string<Throwable>}>
     */
    public static function refusalsOfAdd(): iterable
    {
        yield 'adresse' => [EmailAlreadyUsedException::alreadyLinkedToAnAccount(), EmailAlreadyUsedException::class];
        yield 'identifiant' => [UsernameAlreadyUsedException::takenMeanwhile(), UsernameAlreadyUsedException::class];
    }

    /**
     * Le verrou d'attribution entoure les vérifications et l'enregistrement,
     * et le message n'est publié qu'une fois le verrou rendu — donc la
     * transaction validée (issue #364).
     */
    public function testInviteChecksAndSavesUnderTheAllocationLockThenDispatchesOutsideIt(): void
    {
        // Le verrou tient aussi le journal des appels du dépôt et du bus, et
        // dit pour chacun s'il a lieu sous le verrou.
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
                    $this->record('unlock');
                }
            }

            public function record(string $call): void
            {
                $this->events[] = $call.($this->locked ? ' (verrou)' : '');
            }
        };

        $cpgUserRepository = self::createStub(CpgUserRepositoryInterface::class);
        $cpgUserRepository->method('findOneByEmail')->willReturnCallback(static function () use ($trace): null {
            $trace->record('findOneByEmail');

            return null;
        });
        $cpgUserRepository->method('findOneByUsername')->willReturnCallback(static function () use ($trace): null {
            $trace->record('findOneByUsername');

            return null;
        });
        $cpgUserRepository->method('add')->willReturnCallback(static function () use ($trace): void {
            $trace->record('add');
        });

        $messageBus = self::createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(static function (object $message) use ($trace): Envelope {
            $trace->record('dispatch');

            return new Envelope($message);
        });

        new CpgUserInviter(
            $cpgUserRepository,
            new UsernameGenerator($cpgUserRepository),
            $messageBus,
            new MockClock('2026-10-12 12:00:00'),
            self::createStub(SecurityAuditLoggerInterface::class),
            $trace,
        )->invite('jean@example.com', Locale::FR);

        self::assertSame(
            ['findOneByEmail (verrou)', 'findOneByUsername (verrou)', 'add (verrou)', 'unlock', 'dispatch'],
            $trace->events,
        );
    }

    /**
     * Round de correction (C1) : la relance doit repousser le délai de purge
     * (issue #238) exactement comme la première invitation, en remettant
     * `invitedAt` à l'instant de l'horloge et en écrivant *avant* de
     * redispatcher — sinon le seuil de 30 jours court toujours depuis la
     * toute première invitation. Issue #435 : l'écriture avance la génération
     * en base, atomiquement, et c'est la génération rendue par la base que
     * porte le message, jamais un recomptage en mémoire.
     */
    public function testReinviteAdvancesTheGenerationInTheDatabaseBeforeDispatching(): void
    {
        $clock = new MockClock('2026-09-22 08:00:00');
        $user = new CpgUser('newcomer', '');
        $user->setEmail('newcomer@example.com');
        $user->markInvited(new DateTimeImmutable('2026-09-01 09:00:00'));

        $written = false;
        $cpgUserRepository = $this->createMock(CpgUserRepositoryInterface::class);
        $cpgUserRepository->expects(self::never())->method('add');
        // 5 et non 2 : une relance concurrente a déjà avancé la base.
        $cpgUserRepository->expects(self::once())->method('advanceInvitationGeneration')
            ->with($user, $clock->now())
            ->willReturnCallback(static function () use (&$written): int {
                $written = true;

                return 5;
            });

        $dispatched = null;
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched, &$written): Envelope {
                self::assertTrue($written, 'Le message part avant l\'écriture de la relance.');
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
            $this->lockRunningTheOperation(),
        );

        $inviter->reinvite($user, Locale::EN);

        self::assertEquals($clock->now(), $user->getInvitedAt());
        self::assertInstanceOf(SendAccountInvitationMessage::class, $dispatched);
        self::assertSame($user->getId()->toRfc4122(), $dispatched->userId);
        self::assertSame('en', $dispatched->locale);
        self::assertSame(5, $dispatched->invitationGeneration);
        self::assertSame(5, $user->getInvitationGeneration());
    }

    public function testReinviteRejectsAnAlreadyActivatedAccount(): void
    {
        $user = new CpgUser('active', 'hashed-password');
        $user->setEmail('active@example.com');
        $user->markInvited(new DateTimeImmutable('2026-09-01 09:00:00'));
        $user->markActivated(new DateTimeImmutable('2026-09-02 10:00:00'));

        $cpgUserRepository = self::createStub(CpgUserRepositoryInterface::class);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $inviter = new CpgUserInviter(
            $cpgUserRepository,
            new UsernameGenerator($cpgUserRepository),
            $messageBus,
            new MockClock(),
            $this->auditLoggerExpectingNothing(),
            $this->lockRunningTheOperation(),
        );

        $this->expectException(AccountNotAwaitingActivationException::class);

        $inviter->reinvite($user, Locale::FR);
    }

    /**
     * Issue #485 : la file refuse ou ne répond pas après le save(). Le compte
     * tout juste créé est retiré dans la foulée : l'administrateur reçoit un
     * 503 et réessaie sans buter sur « adresse déjà utilisée », et le journal
     * n'annonce pas une invitation jamais partie.
     */
    public function testInviteRemovesTheAccountWhenTheInvitationCannotBeQueued(): void
    {
        $saved = null;
        $cpgUserRepository = $this->createMock(CpgUserRepositoryInterface::class);
        $cpgUserRepository->method('findOneByEmail')->willReturn(null);
        $cpgUserRepository->method('findOneByUsername')->willReturn(null);
        $cpgUserRepository->expects(self::once())->method('add')->willReturnCallback(static function (CpgUser $user) use (&$saved): void {
            $saved = $user;
        });
        $cpgUserRepository->expects(self::once())->method('removePendingInvitation')->willReturnCallback(static function (CpgUser $user) use (&$saved): void {
            self::assertSame($saved, $user, 'Seul le compte créé par cette invitation est retiré.');
        });

        $messageBus = self::createStub(MessageBusInterface::class);
        // Ce que lève le bus réel depuis #491
        // (App\Shared\Infrastructure\Messenger\QueueUnavailableMiddleware).
        $messageBus->method('dispatch')->willThrowException(new InvitationQueueUnavailableException(new TransportException('Connection refused')));

        $inviter = new CpgUserInviter(
            $cpgUserRepository,
            new UsernameGenerator($cpgUserRepository),
            $messageBus,
            new MockClock(),
            $this->auditLoggerExpectingNothing(),
            $this->lockRunningTheOperation(),
        );

        $this->expectException(InvitationQueueUnavailableException::class);

        $inviter->invite('jean.dupont@example.com', Locale::FR);
    }

    /**
     * La relance porte sur un compte qui existe déjà : rien à retirer. Sa
     * génération reste avancée en base (limite déjà écrite) ; aucun audit.
     */
    public function testReinviteReportsAQueueFailureWithoutAuditOrRemoval(): void
    {
        $user = new CpgUser('newcomer', '');
        $user->setEmail('newcomer@example.com');
        $user->markInvited(new DateTimeImmutable('2026-09-01 09:00:00'));

        $cpgUserRepository = $this->createMock(CpgUserRepositoryInterface::class);
        $cpgUserRepository->method('advanceInvitationGeneration')->willReturn(2);
        $cpgUserRepository->expects(self::never())->method('removePendingInvitation');

        $messageBus = self::createStub(MessageBusInterface::class);
        // Ce que lève le bus réel depuis #491
        // (App\Shared\Infrastructure\Messenger\QueueUnavailableMiddleware).
        $messageBus->method('dispatch')->willThrowException(new InvitationQueueUnavailableException(new TransportException('Connection refused')));

        $inviter = new CpgUserInviter(
            $cpgUserRepository,
            new UsernameGenerator($cpgUserRepository),
            $messageBus,
            new MockClock(),
            $this->auditLoggerExpectingNothing(),
            $this->lockRunningTheOperation(),
        );

        $this->expectException(InvitationQueueUnavailableException::class);

        $inviter->reinvite($user, Locale::FR);
    }

    /**
     * Le verrou d'attribution réduit à ce que ces tests unitaires en
     * attendent : exécuter l'opération. Sa sérialisation réelle est couverte
     * par InviteUsernameConcurrencyTest.
     */
    private function lockRunningTheOperation(): UsernameAllocationLockInterface
    {
        return new class implements UsernameAllocationLockInterface {
            public function whileAllocating(Closure $operation): mixed
            {
                return $operation();
            }
        };
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
