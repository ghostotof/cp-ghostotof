<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Infrastructure\Messenger;

use App\Security\User\Application\Message\SendAccountInvitationMessage;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Entity\PasswordSetupToken;
use App\Security\User\Domain\Exception\AccountInvitationDeliveryException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Security\User\Domain\Repository\PasswordSetupTokenRepositoryInterface;
use App\Security\User\Infrastructure\Messenger\SendAccountInvitationHandler;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Périmètre du handler depuis l'audit C2 (décision D3) : c'est lui qui
 * (re)crée l'unique PasswordSetupToken du compte, dans une transaction, puis
 * envoie l'e-mail. Le message d'entrée ne porte plus que { userId, locale }.
 */
final class SendAccountInvitationHandlerTest extends TestCase
{
    private const string SENDER_EMAIL = 'noreply@cp-ghostotof.com';
    private const string FRONTEND_BASE_URL = 'https://front.test';
    private const int USER_ID = 42;

    public function testItCreatesAFreshTokenAndSendsALocalisedInvitationEmail(): void
    {
        $user = $this->pendingUser('newcomer', 'newcomer@example.com');

        $savedToken = null;
        $tokenRepository = $this->createMock(PasswordSetupTokenRepositoryInterface::class);
        $tokenRepository->expects(self::once())->method('deleteForUser')->with($user);
        $tokenRepository->expects(self::once())->method('save')->willReturnCallback(
            static function (PasswordSetupToken $token) use (&$savedToken): void {
                $savedToken = $token;
            },
        );

        $sentEmail = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')->willReturnCallback(
            static function (TemplatedEmail $email) use (&$sentEmail): void {
                $sentEmail = $email;
            },
        );

        $handler = $this->handler($user, $tokenRepository, $mailer, new MockClock('2026-09-03 12:00:00'));

        $handler(new SendAccountInvitationMessage(self::USER_ID, 'fr'));

        self::assertInstanceOf(PasswordSetupToken::class, $savedToken);
        self::assertSame(64, \strlen($savedToken->getTokenHash()));
        self::assertSame('2026-09-05 12:00:00', $savedToken->getExpiresAt()->format('Y-m-d H:i:s'));

        self::assertInstanceOf(TemplatedEmail::class, $sentEmail);
        self::assertSame(['newcomer@example.com'], array_map(
            static fn (Address $address): string => $address->getAddress(),
            $sentEmail->getTo(),
        ));
        self::assertSame([self::SENDER_EMAIL], array_map(
            static fn (Address $address): string => $address->getAddress(),
            $sentEmail->getFrom(),
        ));
        self::assertNotSame('', (string) $sentEmail->getSubject());
        self::assertSame('emails/account_invitation.html.twig', $sentEmail->getHtmlTemplate());
        self::assertSame('emails/account_invitation.txt.twig', $sentEmail->getTextTemplate());

        $context = $sentEmail->getContext();
        self::assertSame('newcomer', $context['username']);
        self::assertArrayHasKey('strings', $context);

        // Le lien porte le jeton EN CLAIR ; seul son SHA-256 est persisté.
        self::assertSame(1, preg_match(
            '#^https://front\.test/fr/set-password/([0-9a-f]{64})$#',
            $context['setupUrl'],
            $matches,
        ));
        self::assertSame($savedToken->getTokenHash(), hash('sha256', $matches[1]));
    }

    public function testRetryPurgesThePreviousTokenAndIssuesANewOne(): void
    {
        $user = $this->pendingUser('newcomer', 'newcomer@example.com');

        $savedHashes = [];
        $tokenRepository = $this->createMock(PasswordSetupTokenRepositoryInterface::class);
        $tokenRepository->expects(self::exactly(2))->method('deleteForUser')->with($user);
        $tokenRepository->expects(self::exactly(2))->method('save')->willReturnCallback(
            static function (PasswordSetupToken $token) use (&$savedHashes): void {
                $savedHashes[] = $token->getTokenHash();
            },
        );

        $setupUrls = [];
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::exactly(2))->method('send')->willReturnCallback(
            static function (TemplatedEmail $email) use (&$setupUrls): void {
                $setupUrls[] = $email->getContext()['setupUrl'];
            },
        );

        $handler = $this->handler($user, $tokenRepository, $mailer, new MockClock('2026-09-03 12:00:00'));

        $message = new SendAccountInvitationMessage(self::USER_ID, 'en');
        $handler($message);
        $handler($message);

        self::assertCount(2, array_unique($savedHashes), 'Chaque passage doit générer un jeton distinct.');
        self::assertNotSame($setupUrls[0], $setupUrls[1]);
    }

    public function testItDoesNothingWhenTheAccountIsUnknown(): void
    {
        $cpgUserRepository = self::createStub(CpgUserRepositoryInterface::class);
        $cpgUserRepository->method('findOneById')->willReturn(null);

        $tokenRepository = $this->createMock(PasswordSetupTokenRepositoryInterface::class);
        $tokenRepository->expects(self::never())->method('deleteForUser');
        $tokenRepository->expects(self::never())->method('save');

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $handler = new SendAccountInvitationHandler(
            $mailer,
            $cpgUserRepository,
            $tokenRepository,
            $this->passthroughEntityManager(),
            new MockClock(),
            $logger,
            self::SENDER_EMAIL,
            self::FRONTEND_BASE_URL,
        );

        $handler(new SendAccountInvitationMessage(self::USER_ID, 'fr'));
    }

    public function testItDoesNothingWhenTheAccountIsAlreadyActivated(): void
    {
        $user = $this->pendingUser('newcomer', 'newcomer@example.com');
        $user->markActivated(new \DateTimeImmutable('2026-09-01 09:00:00'));

        $tokenRepository = $this->createMock(PasswordSetupTokenRepositoryInterface::class);
        $tokenRepository->expects(self::never())->method('save');

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $handler = $this->handler($user, $tokenRepository, $mailer, new MockClock());

        $handler(new SendAccountInvitationMessage(self::USER_ID, 'fr'));
    }

    public function testItWrapsAMailerTransportFailureIntoADeliveryException(): void
    {
        $user = $this->pendingUser('newcomer', 'newcomer@example.com');
        $transportException = new TransportException('SMTP indisponible.');

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')->willThrowException($transportException);

        $handler = $this->handler(
            $user,
            self::createStub(PasswordSetupTokenRepositoryInterface::class),
            $mailer,
            new MockClock(),
        );

        try {
            $handler(new SendAccountInvitationMessage(self::USER_ID, 'en'));
            self::fail('AccountInvitationDeliveryException aurait dû être levée.');
        } catch (AccountInvitationDeliveryException $exception) {
            self::assertSame($transportException, $exception->getPrevious());
        }
    }

    private function pendingUser(string $username, string $email): CpgUser
    {
        $user = new CpgUser($username, '');
        $user->setEmail($email);
        $user->markInvited(new \DateTimeImmutable('2026-09-03 11:00:00'));

        $id = new \ReflectionProperty(CpgUser::class, 'id');
        $id->setValue($user, self::USER_ID);

        return $user;
    }

    private function handler(
        CpgUser $user,
        PasswordSetupTokenRepositoryInterface $tokenRepository,
        MailerInterface $mailer,
        MockClock $clock,
    ): SendAccountInvitationHandler {
        $cpgUserRepository = self::createStub(CpgUserRepositoryInterface::class);
        $cpgUserRepository->method('findOneById')->willReturn($user);

        return new SendAccountInvitationHandler(
            $mailer,
            $cpgUserRepository,
            $tokenRepository,
            $this->passthroughEntityManager(),
            $clock,
            new NullLogger(),
            self::SENDER_EMAIL,
            self::FRONTEND_BASE_URL,
        );
    }

    /**
     * EntityManager dont seule wrapInTransaction() est utile ici : elle exécute
     * la fermeture reçue, comme le ferait une vraie transaction validée.
     */
    private function passthroughEntityManager(): EntityManagerInterface
    {
        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willReturnCallback(
            static fn (callable $func): mixed => $func($entityManager),
        );

        return $entityManager;
    }
}
