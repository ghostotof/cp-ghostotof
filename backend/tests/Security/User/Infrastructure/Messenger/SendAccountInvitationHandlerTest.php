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
use App\Tests\Support\DrivesTheMailerChain;
use ArrayObject;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stringable;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Mailer\Envelope as MailerEnvelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;
use Throwable;
use Twig\Error\RuntimeError;
use Twig\Source;

/**
 * Périmètre du handler depuis l'audit C2 (décision D3) : c'est lui qui
 * (re)crée l'unique PasswordSetupToken du compte, dans une transaction, puis
 * envoie l'e-mail. Le message d'entrée ne porte que { userId, locale,
 * invitedAt } (issue #434 pour le dernier).
 */
final class SendAccountInvitationHandlerTest extends TestCase
{
    use DrivesTheMailerChain;

    private const string SENDER_EMAIL = 'noreply@cp-ghostotof.com';
    private const string FRONTEND_BASE_URL = 'https://front.test';
    /** Le message transporte l'identifiant en chaîne RFC 4122 (spec 0003 D7). */
    private const string USER_ID = '01998b2e-2d2c-73f4-9f39-8f5b0c1f0a11';
    /** Dernière invitation du compte bouchonné, celle que sert le message (issue #434). */
    private const string INVITED_AT = '2026-09-03 11:00:00';

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

        $handler(self::invitationMessage('fr'));

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

        $message = self::invitationMessage('en');
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

        $handler(self::invitationMessage('fr'));
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

        $handler(self::invitationMessage('fr'));
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
            $handler(self::invitationMessage('en'));
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
     * Le gabarit cassé, et si l'exception du rendu, telle quelle, cite le jeton.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function brokenInvitationTemplates(): iterable
    {
        // Le cas cité par l'issue : une variable inconnue sous strict_variables.
        // Il ne fuyait pas : le message ne cite aucune valeur, et
        // ErrorDetailsStamp::create() vide la trace structurée (arguments
        // compris) ; reste `traceAsString`, dont aucune frame ne reçoit l'URL
        // ici. Gardé comme garde du scénario.
        yield 'variable inconnue (strict_variables)' => ["{{ setupUrl }}\n{{ undefinedVariable }}", false];
        // Twig cite la clé introuvable dans son message : ici, l'URL entière.
        yield 'message qui cite une valeur du contexte' => ['{{ strings[setupUrl] }}', true];
        // Le filtre reçoit l'URL en argument direct : `traceAsString` la garde
        // dès que `zend.exception_string_param_max_len` dépasse sa longueur.
        yield 'URL en argument d\'une frame' => ["{{ setupUrl|escape('unknown') }}", true];
    }

    /**
     * Issue #419 : le jeton naît et est validé en base avant le rendu du
     * gabarit. Si le rendu échoue, l'exception remonte jusqu'au worker, qui
     * pose un {@see ErrorDetailsStamp} sur l'enveloppe : il suit chaque
     * réessai (RabbitMQ) puis le failure_transport (`messenger:failed:show`).
     * Le listener de réessai journalise en plus le message de l'échec et
     * l'exception. Ni l'un ni l'autre ne doit livrer un jeton utilisable
     * (audit C2), quelle que soit la verbosité des traces (`zend.exception_*`
     * du dev et du test, et au pire une longueur de paramètre relevée).
     */
    #[DataProvider('brokenInvitationTemplates')]
    public function testARenderingFailureLeavesNoUsableTokenInTheWorkerFailure(
        string $brokenTextTemplate,
        bool $renderingFailureQuotesTheToken,
    ): void {
        $failure = $this->failToRender($brokenTextTemplate);

        // Contrôle positif : sans le correctif, ce cas fuirait bel et bien.
        // Si une version de Twig ou de PHP cesse de citer la valeur, le cas
        // deviendrait vert à vide : c'est ici qu'il le dirait.
        self::assertSame(
            $renderingFailureQuotesTheToken,
            str_contains($failure['renderingFailureAsString'], $failure['clearToken']),
        );

        // Ce que la table du failure_transport stocke : l'enveloppe sérialisée
        // (Symfony\Component\Messenger\Transport\Serialization\PhpSerializer),
        // stamp compris.
        self::assertStringNotContainsString(
            $failure['clearToken'],
            $failure['serializedStamp'],
            'Le jeton d\'invitation en clair est dans l\'ErrorDetailsStamp.',
        );
        // Ce que les logs du worker reçoivent : `(string)` réunit les messages
        // et la `traceAsString` de toute la chaîne `previous`.
        self::assertStringNotContainsString(
            $failure['clearToken'],
            $failure['workerFailureAsString'],
            'Le jeton d\'invitation en clair est dans l\'échec journalisé par le worker.',
        );
    }

    /**
     * Le rendu raté devient l'exception de domaine, chaîne coupée comme pour le
     * transport (issue #356). Le message garde ce qui aide à corriger sans rien
     * citer du contexte : la classe de l'échec, le gabarit et la ligne.
     */
    public function testARenderingFailureBecomesADeliveryExceptionNamingOnlyTheTemplateAndLine(): void
    {
        $stamp = ErrorDetailsStamp::create(
            $this->failToRender("{{ strings.heading }}\n{{ undefinedVariable }}")['workerFailure'],
        );

        self::assertSame(AccountInvitationDeliveryException::class, $stamp->getExceptionClass());
        self::assertNotNull($stamp->getFlattenException());
        self::assertNull(
            $stamp->getFlattenException()->getPrevious(),
            'Aucune exception du rendu ne doit rester en `previous`.',
        );
        self::assertStringContainsString(
            '(Twig\Error\RuntimeError, emails/account_invitation.txt.twig:2)',
            $stamp->getExceptionMessage(),
        );
        self::assertStringNotContainsString('undefinedVariable', $stamp->getExceptionMessage());
    }

    /**
     * La politique de réessai ne change pas : avant #419 le worker recevait
     * l'exception Twig, après il reçoit l'exception de domaine ; aucune n'est
     * « unrecoverable » ni « recoverable », la stratégie du transport décide.
     * Vert avant comme après le correctif : c'est ce qu'il épingle.
     */
    public function testARenderingFailureIsStillRetriedByTheTransportStrategy(): void
    {
        $failure = $this->failToRender('{{ undefinedVariable }}');

        $sender = new InMemoryTransport();
        $listener = new SendFailedMessageForRetryListener(
            new ServiceLocator(['async' => static fn (): InMemoryTransport => $sender]),
            new ServiceLocator(['async' => static fn (): MultiplierRetryStrategy => new MultiplierRetryStrategy(3)]),
        );
        $event = new WorkerMessageFailedEvent(
            new Envelope(self::invitationMessage('fr')),
            'async',
            $failure['workerFailure'],
        );

        $listener->onMessageFailed($event);

        self::assertTrue($event->willRetry());
        self::assertCount(1, $sender->getSent());
    }

    /**
     * Le rendu a lieu une seconde fois derrière le bus du mailer : un échec y
     * arrive enveloppé dans une HandlerFailedException (une par bus traversé),
     * dont le nom ne dit rien. C'est l'exception du fond qui est décrite, par
     * son type et son emplacement, sans son message.
     */
    public function testAFailureWrappedByTheMailerBusIsDescribedByTheInnermostException(): void
    {
        $envelope = new Envelope(new SendEmailMessage(new TemplatedEmail()));
        $mailer = self::createStub(MailerInterface::class);
        $mailer->method('send')->willThrowException(new HandlerFailedException($envelope, [
            new HandlerFailedException($envelope, [new LogicException('https://front.test/fr/set-password#sentinel')]),
        ]));

        $exception = $this->deliveryFailureWith($mailer);

        self::assertMatchesRegularExpression(
            '~\(LogicException, SendAccountInvitationHandlerTest\.php:\d+\)~',
            $exception->getMessage(),
        );
        self::assertStringNotContainsString('sentinel', $exception->getMessage());
    }

    /**
     * Enveloppé par les bus du mailer, l'échec du transport reste un échec du
     * transport : sa classe et son code, jamais son message (issue #427).
     */
    public function testAWrappedTransportFailureKeepsItsClassAndCode(): void
    {
        $envelope = new Envelope(new SendEmailMessage(new TemplatedEmail()));
        $mailer = self::createStub(MailerInterface::class);
        $mailer->method('send')->willThrowException(new HandlerFailedException($envelope, [
            new HandlerFailedException($envelope, [new TransportException('550 <sentinel@example.com> rejected', 550)]),
        ]));

        $message = $this->deliveryFailureWith($mailer)->getMessage();

        self::assertStringContainsString(TransportException::class.', code 550', $message);
        self::assertStringNotContainsString('sentinel', $message);
    }

    /**
     * Twig rend -1 quand il ne sait pas la ligne : le gabarit seul, alors.
     */
    public function testATemplateErrorWithoutAKnownLineNamesTheTemplateOnly(): void
    {
        $mailer = self::createStub(MailerInterface::class);
        $mailer->method('send')->willThrowException(
            new RuntimeError('sentinel', -1, new Source('', 'emails/account_invitation.html.twig')),
        );

        $message = $this->deliveryFailureWith($mailer)->getMessage();

        self::assertStringContainsString('(Twig\Error\RuntimeError, emails/account_invitation.html.twig)', $message);
        self::assertStringNotContainsString('sentinel', $message);
    }

    /**
     * Le jeton est déjà valide quand l'e-mail se construit : une adresse que
     * Mime refuse (un compte invité avant `email_validation_mode: strict`) ne
     * doit pas faire remonter son RfcComplianceException, qui la cite
     * (issue #356), dans le stamp ni dans les logs du worker.
     */
    public function testARecipientAddressMimeRefusesIsNeverQuoted(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $exception = $this->deliveryFailureWith($mailer, 'sentinel..recipient@example.com');

        self::assertStringContainsString('RfcComplianceException', $exception->getMessage());
        self::assertStringNotContainsString('sentinel', $exception->getMessage());
    }

    /**
     * L'échec de l'envoi, et le mot de son message qui dit quelle fabrique de
     * l'exception de domaine l'a converti.
     *
     * @return iterable<string, array{Throwable, string}>
     */
    public static function deliveryFailures(): iterable
    {
        yield 'transport' => [new TransportException('Connexion impossible'), TransportException::class];
        yield 'autre échec de l\'envoi' => [new LogicException('rendu impossible'), LogicException::class.', SendAccountInvitationHandlerTest.php:'];
    }

    /**
     * Issue #425 : si le retrait du jeton non remis échoue à son tour, c'est
     * toujours l'échec de l'envoi qui remonte au worker — sa politique de
     * réessai en dépend —, et le retrait raté est journalisé par sa seule
     * classe : le message d'une exception Doctrine peut citer le condensat.
     * L'identifiant du jeton, qui n'est pas un secret, permet de le révoquer
     * seul.
     */
    #[DataProvider('deliveryFailures')]
    public function testAFailedTokenRemovalNeitherReplacesTheDeliveryFailureNorQuotesItsMessage(Throwable $failure, string $describedAs): void
    {
        $savedTokenId = null;
        $tokenRepository = self::createStub(PasswordSetupTokenRepositoryInterface::class);
        $tokenRepository->method('save')->willReturnCallback(
            static function (PasswordSetupToken $token) use (&$savedTokenId): void {
                $savedTokenId = $token->getId()->toRfc4122();
            },
        );
        $tokenRepository->method('remove')->willThrowException(new LogicException('DELETE … token_hash = sentinel'));
        $mailer = self::createStub(MailerInterface::class);
        $mailer->method('send')->willThrowException($failure);

        $logged = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->willReturnCallback(
            static function (string|Stringable $message, array $context) use (&$logged): void {
                $logged = ['message' => (string) $message, 'context' => $context];
            },
        );

        $cpgUserRepository = self::createStub(CpgUserRepositoryInterface::class);
        $cpgUserRepository->method('findOneById')->willReturn($this->pendingUser('newcomer', 'newcomer@example.com'));
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

        try {
            $handler(self::invitationMessage('fr'));
            self::fail('AccountInvitationDeliveryException aurait dû être levée.');
        } catch (AccountInvitationDeliveryException $exception) {
            self::assertNull($exception->getPrevious());
            self::assertStringContainsString($describedAs, $exception->getMessage());
        }

        self::assertIsString($savedTokenId);
        self::assertSame(
            ['exceptionClass' => LogicException::class, 'userId' => self::USER_ID, 'tokenId' => $savedTokenId],
            $logged['context'] ?? null,
        );
        self::assertStringNotContainsString('sentinel', json_encode($logged, \JSON_THROW_ON_ERROR));
    }

    /**
     * Invite le compte et rend l'exception de domaine levée, contrôlée sans
     * `previous` (issue #356, #419).
     */
    private function deliveryFailureWith(MailerInterface $mailer, string $email = 'newcomer@example.com'): AccountInvitationDeliveryException
    {
        $handler = $this->handler(
            $this->pendingUser('newcomer', $email),
            self::createStub(PasswordSetupTokenRepositoryInterface::class),
            $mailer,
            new MockClock(),
        );

        try {
            $handler(self::invitationMessage('fr'));
        } catch (AccountInvitationDeliveryException $exception) {
            self::assertNull($exception->getPrevious());

            return $exception;
        }

        self::fail('AccountInvitationDeliveryException aurait dû être levée.');
    }

    private static function invitationMessage(string $locale): SendAccountInvitationMessage
    {
        return new SendAccountInvitationMessage(self::USER_ID, $locale, (new DateTimeImmutable(self::INVITED_AT))->getTimestamp());
    }

    private function pendingUser(string $username, string $email): CpgUser
    {
        $user = new CpgUser($username, '');
        $user->setEmail($email);
        $user->markInvited(new DateTimeImmutable(self::INVITED_AT));

        // L'identité du compte rechargé n'a pas à correspondre à celle du
        // message : le dépôt est bouchonné, c'est lui qui décide ce qu'il rend.
        return $user;
    }

    /**
     * Fait échouer le rendu de l'e-mail par la vraie chaîne du mailer
     * ({@see DrivesTheMailerChain::renderingMailer()}), derrière un vrai bus Messenger comme
     * dans le worker. Rend le jeton émis, l'échec que le worker reçoit, et
     * leurs formes texte : l'exception du rendu telle que send() la lève, le
     * stamp sérialisé, l'échec du worker tel qu'un log l'écrit. PHP tronque
     * les arguments d'une trace au moment où il la formate, pas à la capture :
     * ces chaînes naissent donc sous les réglages verbeux, comme le stamp que
     * le worker pose dans la foulée de l'échec.
     *
     * @return array{clearToken: string, workerFailure: HandlerFailedException, renderingFailureAsString: string, serializedStamp: string, workerFailureAsString: string}
     */
    private function failToRender(string $brokenTextTemplate): array
    {
        $mailer = new class($this->renderingMailer([
            'emails/account_invitation.html.twig' => '<p>{{ setupUrl }}</p>',
            'emails/account_invitation.txt.twig' => $brokenTextTemplate,
        ])) implements MailerInterface {
            public ?string $setupUrl = null;
            public ?Throwable $failure = null;

            public function __construct(private readonly MailerInterface $inner)
            {
            }

            public function send(RawMessage $message, ?MailerEnvelope $envelope = null): void
            {
                // Le jeton en clair n'existe que dans le contexte de l'e-mail.
                if ($message instanceof TemplatedEmail) {
                    $setupUrl = $message->getContext()['setupUrl'] ?? null;
                    $this->setupUrl = \is_string($setupUrl) ? $setupUrl : null;
                }

                try {
                    $this->inner->send($message, $envelope);
                } catch (Throwable $exception) {
                    $this->failure = $exception;

                    throw $exception;
                }
            }
        };

        // Le condensat enregistré, lu après coup par la fermeture du dispatch.
        /** @var ArrayObject<string, string> $savedToken */
        $savedToken = new ArrayObject();
        $tokenRepository = self::createStub(PasswordSetupTokenRepositoryInterface::class);
        $tokenRepository->method('save')->willReturnCallback(
            static function (PasswordSetupToken $token) use ($savedToken): void {
                $savedToken['hash'] = $token->getTokenHash();
            },
        );

        $handler = $this->handler(
            $this->pendingUser('newcomer', 'newcomer@example.com'),
            $tokenRepository,
            $mailer,
            new MockClock(),
        );
        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            SendAccountInvitationMessage::class => [$handler],
        ]))]);

        return $this->withVerboseTraces(static function () use ($bus, $mailer, $savedToken): array {
            try {
                $bus->dispatch(self::invitationMessage('fr'));
                self::fail('Le rendu aurait dû échouer.');
            } catch (HandlerFailedException $workerFailure) {
                self::assertInstanceOf(Throwable::class, $mailer->failure);
                self::assertIsString($mailer->setupUrl);
                self::assertSame(1, preg_match('~#([0-9a-f]{64})\z~', $mailer->setupUrl, $matches));
                // La prémisse de #419 : ce jeton est déjà valide en base.
                self::assertSame($savedToken['hash'] ?? null, hash('sha256', $matches[1]));

                return [
                    'clearToken' => $matches[1],
                    'workerFailure' => $workerFailure,
                    'renderingFailureAsString' => (string) $mailer->failure,
                    'serializedStamp' => serialize(ErrorDetailsStamp::create($workerFailure)),
                    'workerFailureAsString' => (string) $workerFailure,
                ];
            }
        });
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
