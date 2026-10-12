<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Messenger;

use App\Contact\Application\Message\SendContactMessageMessage;
use App\Contact\Domain\Exception\ContactMessageQueueUnavailableException;
use App\Shared\Application\Message\MessengerProbeMessage;
use App\Shared\Infrastructure\Messenger\QueueUnavailableMiddleware;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Throwable;

/**
 * Issue #491 : la conversion d'une panne de la file en 503 nommé, écrite une
 * fois. Le câblage sur le bus réel est vérifié par AsyncPublishFailureTest.
 */
final class QueueUnavailableMiddlewareTest extends TestCase
{
    public function testAPublishFailureBecomesTheExceptionTheMessageNames(): void
    {
        $cause = new TransportException('Server channel error: 406, PRECONDITION_FAILED');

        try {
            new QueueUnavailableMiddleware()->handle(new Envelope($this->contactMessage()), $this->stackThrowing($cause));
            self::fail('La panne du broker doit remonter.');
        } catch (ContactMessageQueueUnavailableException $unavailable) {
            self::assertSame($cause, $unavailable->getPrevious());
        }
    }

    /**
     * La sonde de #470 n'implémente pas QueuedMessageInterface : sa commande
     * rattrape elle-même la TransportException pour la citer.
     */
    public function testAMessageThatNamesNoExceptionKeepsTheTransportFailure(): void
    {
        $cause = new TransportException('Could not connect to the AMQP server.');

        $this->expectExceptionObject($cause);

        new QueueUnavailableMiddleware()->handle(new Envelope(new MessengerProbeMessage('probe')), $this->stackThrowing($cause));
    }

    /**
     * Un message reçu par le worker n'est pas publié : une TransportException
     * qui remonterait de son traitement n'est pas la panne d'un envoi.
     */
    public function testAReceivedMessageIsNeverConverted(): void
    {
        $cause = new TransportException('Could not connect to the AMQP server.');

        $this->expectExceptionObject($cause);

        new QueueUnavailableMiddleware()->handle(
            new Envelope($this->contactMessage(), [new ReceivedStamp('async')]),
            $this->stackThrowing($cause),
        );
    }

    public function testAnyOtherFailureIsLeftAlone(): void
    {
        $failure = new RuntimeException('Autre panne.');

        $this->expectExceptionObject($failure);

        new QueueUnavailableMiddleware()->handle(new Envelope($this->contactMessage()), $this->stackThrowing($failure));
    }

    public function testASuccessfulPublicationReturnsTheEnvelopeOfTheRestOfTheStack(): void
    {
        $sent = new Envelope($this->contactMessage());
        $next = self::createStub(MiddlewareInterface::class);
        $next->method('handle')->willReturn($sent);

        self::assertSame($sent, new QueueUnavailableMiddleware()->handle(new Envelope($this->contactMessage()), new StackMiddleware($next)));
    }

    private function stackThrowing(Throwable $failure): StackInterface
    {
        $next = self::createStub(MiddlewareInterface::class);
        $next->method('handle')->willThrowException($failure);

        return new StackMiddleware($next);
    }

    private function contactMessage(): SendContactMessageMessage
    {
        return new SendContactMessageMessage(senderName: 'Jane Doe', senderEmail: 'jane@example.com', body: 'Bonjour.');
    }
}
