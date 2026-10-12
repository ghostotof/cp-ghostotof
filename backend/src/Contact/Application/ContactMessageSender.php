<?php

declare(strict_types=1);

namespace App\Contact\Application;

use App\Contact\Application\Message\SendContactMessageMessage;
use App\Contact\Domain\ValueObject\ContactMessage;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class ContactMessageSender implements ContactMessageSenderInterface
{
    public function __construct(
        private MessageBusInterface $messageBus,
    ) {
    }

    public function send(ContactMessage $message): void
    {
        // Une file qui ne prend pas le message lève l'exception qu'il nomme
        // (ContactMessageQueueUnavailableException, issues #485 et #491).
        $this->messageBus->dispatch(new SendContactMessageMessage(
            senderName: $message->senderName,
            senderEmail: $message->senderEmail,
            body: $message->body,
        ));
    }
}
