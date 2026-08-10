<?php

declare(strict_types=1);

namespace App\Contact\Application\Message;

/**
 * Commande Messenger : enveloppe sérialisable dispatchée sur le bus par
 * ContactMessageSender et consommée par
 * App\Contact\Infrastructure\Messenger\SendContactMessageHandler (routée
 * vers le transport "async"/RabbitMQ, cf. config/packages/messenger.yaml).
 * Volontairement distincte de la VO Domain ContactMessage : celle-ci décrit
 * un concept métier, celle-là un contrat de transport Messenger.
 */
final readonly class SendContactMessageMessage
{
    public function __construct(
        public string $senderName,
        public string $senderEmail,
        public string $body,
    ) {
    }
}
