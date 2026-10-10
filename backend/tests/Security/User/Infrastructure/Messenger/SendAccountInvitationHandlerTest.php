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
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bridge\Twig\Mime\BodyRenderer;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mailer\EventListener\MessageListener;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Messenger\MessageHandler;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Mime\Address;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Périmètre du handler depuis l'audit C2 (décision D3) : c'est lui qui
 * (re)crée l'unique PasswordSetupToken du compte, dans une transaction, puis
 * envoie l'e-mail. Le message d'entrée ne porte plus que { userId, locale }.
 */
final class SendAccountInvitationHandlerTest extends TestCase
{
    private const string SENDER_EMAIL = 'noreply@cp-ghostotof.com';
    private const string FRONTEND_BASE_URL = 'https://front.test';
    /** Le message transporte l'identifiant en chaîne RFC 4122 (spec 0003 D7). */
    private const string USER_ID = '01998b2e-2d2c-73f4-9f39-8f5b0c1f0a11';

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

        // Le lien porte le jeton EN CLAIR ; seul son SHA-256 est persisté. Il
        // voyage dans le FRAGMENT (audit A7, décision D6) : un fragment n'est
        // jamais envoyé au serveur, donc absent des access logs du nginx
        // frontend et de l'ingress — contrairement à un segment de chemin.
        self::assertIsString($context['setupUrl']);
        self::assertSame(1, preg_match(
            '~^https://front\.test/fr/set-password#([0-9a-f]{64})$~',
            $context['setupUrl'],
            $matches,
        ));
        self::assertStringNotContainsString('/set-password/', $context['setupUrl']);
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
        $user->markActivated(new DateTimeImmutable('2026-09-01 09:00:00'));

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
        // Une réponse SMTP peut citer une adresse (audit I4, issue #356).
        $transportException = new TransportException('550 5.1.1 <sentinel.recipient@example.com>: Recipient address rejected', 550);

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
            // Chaîne coupée (issue #356) : le worker journalise toute la
            // chaîne `previous`, et le transport de Messenger la sérialise
            // dans la table d'échec. Seuls la classe et le code survivent.
            self::assertNull($exception->getPrevious());
            self::assertStringContainsString(TransportException::class, $exception->getMessage());
            self::assertStringContainsString('550', $exception->getMessage());
            self::assertStringNotContainsString('sentinel.recipient', $exception->getMessage());
        }
    }

    /**
     * Issue #419 : le jeton naît et est validé en base avant le rendu du
     * gabarit. Si le rendu échoue, l'exception remonte jusqu'au worker, qui
     * pose un {@see ErrorDetailsStamp} sur l'enveloppe : il suit chaque
     * réessai (RabbitMQ) puis le failure_transport (`messenger:failed:show`).
     * Ce que le stamp garde — message, `previous`, trace en chaîne — ne doit
     * jamais livrer un jeton utilisable (audit C2), quelle que soit la
     * verbosité des traces (`zend.exception_*`, celle du dev et du test, et au
     * pire une longueur de paramètre relevée).
     *
     * @return iterable<string, array{string}>
     */
    public static function brokenInvitationTemplates(): iterable
    {
        // Le cas cité par l'issue : une variable inconnue sous strict_variables.
        // Vert avant le correctif : le message ne cite aucune valeur, et
        // ErrorDetailsStamp::create() vide la trace (et ses arguments) de
        // l'exception et de chaque `previous`. Gardé comme garde du scénario.
        yield 'variable inconnue (strict_variables)' => ["{{ setupUrl }}\n{{ undefinedVariable }}"];
        // Twig cite la clé introuvable dans son message : ici, l'URL entière.
        yield 'message qui cite une valeur du contexte' => ['{{ strings[setupUrl] }}'];
        // Le filtre reçoit l'URL en argument direct : visible dans la trace.
        yield 'URL en argument d\'une frame' => ["{{ setupUrl|escape('unknown') }}"];
    }

    #[DataProvider('brokenInvitationTemplates')]
    public function testARenderingFailureLeavesNoUsableTokenInTheErrorDetailsStamp(string $brokenTextTemplate): void
    {
        [$stamp, $tokenHash] = $this->failToRender($brokenTextTemplate);

        // Ce que la table du failure_transport stocke : l'enveloppe sérialisée
        // (PhpSerializer), stamp compris.
        $stored = serialize($stamp);

        // Toutes les fenêtres de 64 caractères hexadécimaux, chevauchantes :
        // le jeton peut être collé à d'autres caractères hexadécimaux.
        preg_match_all('/(?=([0-9a-f]{64}))/', $stored, $candidates);
        foreach ($candidates[1] as $candidate) {
            self::assertNotSame(
                $tokenHash,
                hash('sha256', $candidate),
                'Le jeton d\'invitation en clair est dans l\'ErrorDetailsStamp.',
            );
        }
        self::assertStringNotContainsString('set-password#', $stored);
    }

    /**
     * Le rendu raté devient l'exception de domaine, chaîne coupée comme pour le
     * transport (issue #356), et la politique de réessai ne change pas : ni
     * avant ni après #419 l'exception n'est « unrecoverable », Messenger
     * réessaie selon la stratégie du transport puis range le message dans le
     * failure_transport.
     */
    public function testARenderingFailureBecomesADeliveryExceptionThatIsStillRetried(): void
    {
        [$stamp] = $this->failToRender('{{ undefinedVariable }}');

        self::assertSame(AccountInvitationDeliveryException::class, $stamp->getExceptionClass());
        $flattened = $stamp->getFlattenException();
        self::assertNotNull($flattened);
        self::assertNull($flattened->getPrevious(), 'Aucune exception du rendu ne doit rester en `previous`.');
        self::assertStringContainsString('RuntimeError', $stamp->getExceptionMessage());
        self::assertFalse(is_a($stamp->getExceptionClass(), UnrecoverableExceptionInterface::class, true));
    }

    private function pendingUser(string $username, string $email): CpgUser
    {
        $user = new CpgUser($username, '');
        $user->setEmail($email);
        $user->markInvited(new DateTimeImmutable('2026-09-03 11:00:00'));

        // L'identité du compte rechargé n'a pas à correspondre à celle du
        // message : le dépôt est bouchonné, c'est lui qui décide ce qu'il rend.
        return $user;
    }

    /**
     * Fait échouer le rendu de l'e-mail par la vraie chaîne du mailer, câblée
     * comme `mailer.mailer` : l'envoi passe par un bus (SendEmailMessage, traité
     * en synchrone faute de routage), le transport émet le MessageEvent et le
     * MessageListener rend le TemplatedEmail via BodyRenderer et Twig. Le tout
     * derrière un vrai bus Messenger, comme dans le worker, puis construit le
     * stamp que le worker poserait.
     *
     * @return array{ErrorDetailsStamp, string} le stamp et le SHA-256 du jeton émis
     */
    private function failToRender(string $brokenTextTemplate): array
    {
        $tokenHash = null;
        $tokenRepository = self::createStub(PasswordSetupTokenRepositoryInterface::class);
        $tokenRepository->method('save')->willReturnCallback(
            static function (PasswordSetupToken $token) use (&$tokenHash): void {
                $tokenHash = $token->getTokenHash();
            },
        );

        $twig = new Environment(new ArrayLoader([
            'emails/account_invitation.html.twig' => '<p>{{ setupUrl }}</p>',
            'emails/account_invitation.txt.twig' => $brokenTextTemplate,
        ]), ['strict_variables' => true]);
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new MessageListener(null, new BodyRenderer($twig)));
        $transport = new NullTransport($dispatcher);
        $mailer = new Mailer($transport, new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            SendEmailMessage::class => [new MessageHandler($transport)],
        ]))]));

        $handler = $this->handler(
            $this->pendingUser('newcomer', 'newcomer@example.com'),
            $tokenRepository,
            $mailer,
            new MockClock(),
        );
        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            SendAccountInvitationMessage::class => [$handler],
        ]))]);

        // Les réglages du dev et du test (arguments gardés), et au pire une
        // longueur de chaîne relevée : le correctif ne doit pas dépendre d'un
        // php.ini.
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
        $maxLength = ini_set('zend.exception_string_param_max_len', '1000000');

        try {
            $bus->dispatch(new SendAccountInvitationMessage(self::USER_ID, 'fr'));
            self::fail('Le rendu aurait dû échouer.');
        } catch (HandlerFailedException $exception) {
            $stamp = ErrorDetailsStamp::create($exception);
        } finally {
            if (false !== $ignoreArgs) {
                ini_set('zend.exception_ignore_args', $ignoreArgs);
            }
            if (false !== $maxLength) {
                ini_set('zend.exception_string_param_max_len', $maxLength);
            }
        }

        self::assertIsString($tokenHash, 'Le jeton doit avoir été émis avant le rendu.');

        return [$stamp, $tokenHash];
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
