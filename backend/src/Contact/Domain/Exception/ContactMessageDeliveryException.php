<?php

declare(strict_types=1);

namespace App\Contact\Domain\Exception;

/**
 * Exception métier levée lorsque l'envoi effectif de l'email de contact
 * échoue (SMTP indisponible, DSN mal configuré, etc.), typiquement dans
 * App\Contact\Infrastructure\Messenger\SendContactMessageHandler.
 */
final class ContactMessageDeliveryException extends \RuntimeException
{
    public static function becauseTransportFailed(\Throwable $previous): self
    {
        return new self('L\'envoi du message de contact a échoué.', previous: $previous);
    }
}
