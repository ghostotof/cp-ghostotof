<?php

declare(strict_types=1);

namespace App\Tests\Contact\Application;

use App\Contact\Application\ContactMessageSender;
use App\Contact\Application\Message\SendContactMessageMessage;
use App\Contact\Domain\ValueObject\ContactMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
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
}
