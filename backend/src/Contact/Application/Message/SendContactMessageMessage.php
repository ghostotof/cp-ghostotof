<?php

declare(strict_types=1);

namespace App\Contact\Application\Message;

use App\Contact\Domain\Exception\ContactMessageQueueUnavailableException;
use App\Shared\Application\Message\AsyncMessageInterface;
use Throwable;

/**
 * Commande Messenger : enveloppe sérialisable dispatchée sur le bus par
 * ContactMessageSender et consommée par
 * {@see \App\Contact\Infrastructure\Messenger\SendContactMessageHandler} (routée
 * vers le transport "async"/RabbitMQ, cf. config/packages/messenger.yaml).
 * Volontairement distincte de la VO Domain ContactMessage : celle-ci décrit
 * un concept métier, celle-là un contrat de transport Messenger.
 */
final readonly class SendContactMessageMessage implements AsyncMessageInterface
{
    public function __construct(
        public string $senderName,
        public string $senderEmail,
        public string $body,
    ) {
    }

    /**
     * Issue #485 : le quota du visiteur n'est alors pas consommé
     * (ContactMessageProcessor).
     */
    public function queueUnavailable(Throwable $cause): ContactMessageQueueUnavailableException
    {
        return new ContactMessageQueueUnavailableException($cause);
    }
}
