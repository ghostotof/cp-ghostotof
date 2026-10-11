<?php

declare(strict_types=1);

namespace App\Contact\Application;

use App\Contact\Application\Message\SendContactMessageMessage;
use App\Contact\Domain\Exception\ContactMessageQueueUnavailableException;
use App\Contact\Domain\ValueObject\ContactMessage;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class ContactMessageSender implements ContactMessageSenderInterface
{
    public function __construct(
        private MessageBusInterface $messageBus,
    ) {
    }

    public function send(ContactMessage $message): void
    {
        try {
            $this->messageBus->dispatch(new SendContactMessageMessage(
                senderName: $message->senderName,
                senderEmail: $message->senderEmail,
                body: $message->body,
            ));
        } catch (TransportException $failure) {
            // Issue #485 : broker arrêté, refus ou accusé manquant (#479).
            throw new ContactMessageQueueUnavailableException($failure);
        }
    }
}
