<?php

declare(strict_types=1);

namespace App\Tests\Contact\Infrastructure\Messenger;

use App\Contact\Application\Message\SendContactMessageMessage;
use App\Contact\Domain\Exception\ContactMessageDeliveryException;
use App\Contact\Infrastructure\Messenger\SendContactMessageHandler;
use App\Tests\Support\DrivesTheMailerChain;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
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

final class SendContactMessageHandlerTest extends TestCase
{
    use DrivesTheMailerChain;

    /** Ce que le visiteur a saisi : rien ne doit en sortir dans l'échec du worker. */
    private const string VISITOR_NAME = 'Sentinel Visitor';
    private const string VISITOR_EMAIL = 'sentinel.visitor@example.com';
    private const string VISITOR_BODY = 'Sentinel body of the visitor';

    private const string CONTACT_SENDER_EMAIL = 'noreply@cp-ghostotof.com';
    private const string CONTACT_RECIPIENT_EMAIL = 'contact@cp-ghostotof.com';

    public function testItSendsATemplatedEmailToTheRecipientWithReplyToTheVisitorAndSenderIdentityInContext(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())
            ->method('send')
            ->with(self::callback(function (TemplatedEmail $email): bool {
                self::assertSame([self::CONTACT_SENDER_EMAIL], array_map(
                    static fn (Address $address): string => $address->getAddress(),
                    $email->getFrom(),
                ));
                self::assertSame([self::CONTACT_RECIPIENT_EMAIL], array_map(
                    static fn (Address $address): string => $address->getAddress(),
                    $email->getTo(),
                ));
                self::assertSame(['jane@example.com'], array_map(
                    static fn (Address $address): string => $address->getAddress(),
                    $email->getReplyTo(),
                ));
                self::assertStringContainsString('Jane Doe', (string) $email->getSubject());

                // Le corps est désormais rendu par Twig à l'envoi ; le handler
                // ne fait que désigner les templates et fournir le contexte.
                self::assertSame('emails/contact_notification.html.twig', $email->getHtmlTemplate());
                self::assertSame('emails/contact_notification.txt.twig', $email->getTextTemplate());
                self::assertSame([
                    'senderName' => 'Jane Doe',
                    'senderEmail' => 'jane@example.com',
                    'body' => 'Bonjour, ceci est un message.',
                ], $email->getContext());

                return true;
            }));

        $handler = new SendContactMessageHandler($mailer, self::CONTACT_SENDER_EMAIL, self::CONTACT_RECIPIENT_EMAIL);

        $handler(new SendContactMessageMessage(
            senderName: 'Jane Doe',
            senderEmail: 'jane@example.com',
            body: 'Bonjour, ceci est un message.',
        ));
    }

    public function testItWrapsAMailerTransportFailureIntoAContactMessageDeliveryException(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        // Une réponse SMTP peut citer une adresse (audit I4, issue #356).
        $transportException = new TransportException('550 5.1.1 <sentinel.recipient@example.com>: Recipient address rejected', 550);
        $mailer->expects(self::once())->method('send')->willThrowException($transportException);

        $handler = new SendContactMessageHandler($mailer, self::CONTACT_SENDER_EMAIL, self::CONTACT_RECIPIENT_EMAIL);

        try {
            $handler(new SendContactMessageMessage(
                senderName: 'Jane Doe',
                senderEmail: 'jane@example.com',
                body: 'Bonjour, ceci est un message.',
            ));
            self::fail('ContactMessageDeliveryException aurait dû être levée.');
        } catch (ContactMessageDeliveryException $exception) {
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
     * Le gabarit cassé, et le champ du formulaire que l'exception du rendu
     * cite tel quel. Le champ par son nom, jamais sa valeur : la trace
     * verbeuse de l'échec garde les arguments de la méthode de test, la
     * valeur y serait vue quel que soit le correctif.
     *
     * @return iterable<string, array{string, 'senderName'|'senderEmail'|'body'}>
     */
    public static function brokenContactTemplates(): iterable
    {
        // Twig cite la clé introuvable dans son message.
        yield 'message qui cite l\'adresse du visiteur' => ['{{ senderName[senderEmail] }}', 'senderEmail'];
        yield 'message qui cite le contenu du message' => ['{{ senderName[body] }}', 'body'];
        // Le filtre reçoit le nom en argument direct : `traceAsString` le garde.
        yield 'nom en argument d\'une frame' => ["{{ senderName|escape('unknown') }}", 'senderName'];
    }

    /**
     * Issue #427, le pendant de #419 côté contact : une erreur de rendu
     * remonte jusqu'au worker, qui la journalise à chaque tentative et la
     * garde dans l'{@see ErrorDetailsStamp} jusqu'au failure_transport. Ni
     * l'un ni l'autre ne doit citer ce que le visiteur a saisi.
     *
     * @param 'senderName'|'senderEmail'|'body' $field
     */
    #[DataProvider('brokenContactTemplates')]
    public function testARenderingFailureQuotesNoFormDataInTheWorkerFailure(string $brokenTextTemplate, string $field): void
    {
        $quoted = match ($field) {
            'senderName' => self::VISITOR_NAME,
            'senderEmail' => self::VISITOR_EMAIL,
            'body' => self::VISITOR_BODY,
        };
        $failure = $this->failToRender($brokenTextTemplate);

        // Contrôle positif : sans le correctif, ce cas fuirait bel et bien.
        self::assertStringContainsString($quoted, $failure['renderingFailureAsString']);

        self::assertStringNotContainsString($quoted, $failure['serializedStamp'], 'Une donnée du formulaire est dans l\'ErrorDetailsStamp.');
        self::assertStringNotContainsString($quoted, $failure['workerFailureAsString'], 'Une donnée du formulaire est dans l\'échec journalisé par le worker.');
    }

    public function testARenderingFailureBecomesADeliveryExceptionNamingOnlyTheTemplateAndLine(): void
    {
        $stamp = ErrorDetailsStamp::create($this->failToRender("{{ senderName }}\n{{ undefinedVariable }}")['workerFailure']);

        self::assertSame(ContactMessageDeliveryException::class, $stamp->getExceptionClass());
        self::assertNotNull($stamp->getFlattenException());
        self::assertNull($stamp->getFlattenException()->getPrevious(), 'Aucune exception du rendu ne doit rester en `previous`.');
        self::assertStringContainsString('(Twig\Error\RuntimeError, emails/contact_notification.txt.twig:2)', $stamp->getExceptionMessage());
    }

    /**
     * L'adresse que Mime refuse, et le rôle que l'exception doit nommer.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function refusedAddresses(): iterable
    {
        // La validation stricte l'arrête en amont (`email_validation_mode:
        // strict`), mais un message en file avant elle, ou rejoué depuis le
        // failure_transport, passerait.
        yield 'adresse du visiteur' => ['sentinel..visitor@example.com', self::CONTACT_RECIPIENT_EMAIL, 'replyTo'];
        // Un secret manquant après une rotation : chaque message échoue.
        yield 'destinataire de configuration vide' => [self::VISITOR_EMAIL, '', 'to'];
    }

    /**
     * Le RfcComplianceException cite l'adresse : il n'en reste que le rôle
     * de l'adresse refusée, qui dit à l'opérateur si c'est une saisie du
     * visiteur ou sa propre configuration.
     */
    #[DataProvider('refusedAddresses')]
    public function testARefusedAddressIsNamedByItsRoleOnly(string $visitorEmail, string $recipientEmail, string $role): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');
        $handler = new SendContactMessageHandler($mailer, self::CONTACT_SENDER_EMAIL, $recipientEmail);

        try {
            $handler(new SendContactMessageMessage(self::VISITOR_NAME, $visitorEmail, self::VISITOR_BODY));
            self::fail('ContactMessageDeliveryException aurait dû être levée.');
        } catch (ContactMessageDeliveryException $exception) {
            self::assertNull($exception->getPrevious());
            self::assertStringContainsString(\sprintf('adresse « %s » refusée', $role), $exception->getMessage());
            self::assertStringNotContainsStringIgnoringCase('sentinel', $exception->getMessage());
        }
    }

    /**
     * Enveloppé par les bus du mailer, l'échec du transport reste un échec du
     * transport : sa classe et son code (550 : adresse rejetée, pas SMTP
     * indisponible), jamais son message.
     */
    public function testAWrappedTransportFailureKeepsItsClassAndCode(): void
    {
        $envelope = new Envelope(new SendEmailMessage(new TemplatedEmail()));
        $mailer = self::createStub(MailerInterface::class);
        $mailer->method('send')->willThrowException(new HandlerFailedException($envelope, [
            new HandlerFailedException($envelope, [new TransportException('550 <'.self::VISITOR_EMAIL.'> rejected', 550)]),
        ]));
        $handler = new SendContactMessageHandler($mailer, self::CONTACT_SENDER_EMAIL, self::CONTACT_RECIPIENT_EMAIL);

        try {
            $handler($this->visitorMessage());
            self::fail('ContactMessageDeliveryException aurait dû être levée.');
        } catch (ContactMessageDeliveryException $exception) {
            self::assertNull($exception->getPrevious());
            self::assertStringContainsString(TransportException::class.', code 550', $exception->getMessage());
            self::assertStringNotContainsStringIgnoringCase('sentinel', $exception->getMessage());
        }
    }

    /**
     * Derrière le bus du mailer, l'échec arrive enveloppé dans une
     * HandlerFailedException par bus traversé : c'est celle du fond qui est
     * décrite, sans son message.
     */
    public function testAFailureWrappedByTheMailerBusIsDescribedByTheInnermostException(): void
    {
        $envelope = new Envelope(new SendEmailMessage(new TemplatedEmail()));
        $mailer = self::createStub(MailerInterface::class);
        $mailer->method('send')->willThrowException(new HandlerFailedException($envelope, [
            new HandlerFailedException($envelope, [new LogicException(self::VISITOR_EMAIL)]),
        ]));
        $handler = new SendContactMessageHandler($mailer, self::CONTACT_SENDER_EMAIL, self::CONTACT_RECIPIENT_EMAIL);

        try {
            $handler($this->visitorMessage());
            self::fail('ContactMessageDeliveryException aurait dû être levée.');
        } catch (ContactMessageDeliveryException $exception) {
            self::assertNull($exception->getPrevious());
            self::assertMatchesRegularExpression('~\(LogicException, SendContactMessageHandlerTest\.php:\d+\)~', $exception->getMessage());
            self::assertStringNotContainsStringIgnoringCase('sentinel', $exception->getMessage());
        }
    }

    /**
     * La politique de réessai ne change pas : aucune des deux exceptions
     * n'est « (un)recoverable », la stratégie du transport décide. Vert avant
     * comme après le correctif : c'est ce qu'il épingle.
     */
    public function testARenderingFailureIsStillRetriedByTheTransportStrategy(): void
    {
        $failure = $this->failToRender('{{ undefinedVariable }}');

        $sender = new InMemoryTransport();
        $listener = new SendFailedMessageForRetryListener(
            new ServiceLocator(['async' => static fn (): InMemoryTransport => $sender]),
            new ServiceLocator(['async' => static fn (): MultiplierRetryStrategy => new MultiplierRetryStrategy(3)]),
        );
        $event = new WorkerMessageFailedEvent(new Envelope($this->visitorMessage()), 'async', $failure['workerFailure']);

        $listener->onMessageFailed($event);

        self::assertTrue($event->willRetry());
        self::assertCount(1, $sender->getSent());
    }

    /**
     * Fait échouer le rendu de l'e-mail par la vraie chaîne du mailer,
     * derrière un vrai bus Messenger comme dans le worker. Rend l'échec que le
     * worker reçoit et ses formes texte : l'exception du rendu telle que
     * send() la lève, le stamp sérialisé, l'échec tel qu'un log l'écrit.
     *
     * @return array{workerFailure: HandlerFailedException, renderingFailureAsString: string, serializedStamp: string, workerFailureAsString: string}
     */
    private function failToRender(string $brokenTextTemplate): array
    {
        $mailer = new class($this->renderingMailer([
            'emails/contact_notification.html.twig' => '<p>{{ senderName }}</p>',
            'emails/contact_notification.txt.twig' => $brokenTextTemplate,
        ])) implements MailerInterface {
            public ?Throwable $failure = null;

            public function __construct(private readonly MailerInterface $inner)
            {
            }

            public function send(RawMessage $message, ?MailerEnvelope $envelope = null): void
            {
                try {
                    $this->inner->send($message, $envelope);
                } catch (Throwable $exception) {
                    $this->failure = $exception;

                    throw $exception;
                }
            }
        };

        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            SendContactMessageMessage::class => [new SendContactMessageHandler($mailer, self::CONTACT_SENDER_EMAIL, self::CONTACT_RECIPIENT_EMAIL)],
        ]))]);
        $message = $this->visitorMessage();

        return $this->withVerboseTraces(static function () use ($bus, $mailer, $message): array {
            try {
                $bus->dispatch($message);
                self::fail('Le rendu aurait dû échouer.');
            } catch (HandlerFailedException $workerFailure) {
                self::assertInstanceOf(Throwable::class, $mailer->failure);

                return [
                    'workerFailure' => $workerFailure,
                    'renderingFailureAsString' => (string) $mailer->failure,
                    'serializedStamp' => serialize(ErrorDetailsStamp::create($workerFailure)),
                    'workerFailureAsString' => (string) $workerFailure,
                ];
            }
        });
    }

    private function visitorMessage(): SendContactMessageMessage
    {
        return new SendContactMessageMessage(self::VISITOR_NAME, self::VISITOR_EMAIL, self::VISITOR_BODY);
    }
}
