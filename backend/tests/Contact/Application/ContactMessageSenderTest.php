<?php

declare(strict_types=1);

namespace App\Tests\Contact\Application;

use App\Contact\Application\ContactMessageSender;
use App\Contact\Application\Message\SendContactMessageMessage;
use App\Contact\Domain\Exception\ContactMessageQueueUnavailableException;
use App\Contact\Domain\ValueObject\ContactMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;

final class ContactMessageSenderTest extends TestCase
{
    public function testItDispatchesASendContactMessageMessageCarryingTheSameData(): void
    {
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(fn(SendContactMessageMessage $dispatched): bool => 'Jane Doe' === $dispatched->senderName
                && 'jane@example.com' === $dispatched->senderEmail
                && 'Bonjour, ceci est un message.' === $dispatched->body))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $sender = new ContactMessageSender($messageBus);

        $sender->send(new ContactMessage(
            senderName: 'Jane Doe',
            senderEmail: 'jane@example.com',
            body: 'Bonjour, ceci est un message.',
        ));
    }

    /**
     * Issue #485 : une panne de la file (broker arrêté, refus confirmé par le
     * broker) rend un 503 nommé, plus un 500 générique.
     */
    public function testABrokerFailureBecomesAContactQueueUnavailableError(): void
    {
        $cause = new TransportException('Server channel error: 406, PRECONDITION_FAILED');
        $messageBus = self::createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willThrowException($cause);

        try {
            new ContactMessageSender($messageBus)->send(new ContactMessage(senderName: 'Jane Doe', senderEmail: 'jane@example.com', body: 'Bonjour, ceci est un message.'));
            self::fail('La panne du broker doit remonter.');
        } catch (ContactMessageQueueUnavailableException $unavailable) {
            self::assertSame($cause, $unavailable->getPrevious());
        }
    }
}
