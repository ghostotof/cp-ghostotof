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
    /**
     * Jamais l'exception du transport en `previous` (issue #356, audit I4) :
     * son message recopie la réponse du serveur, qui peut citer une adresse,
     * et le worker journalise toute la chaîne. Seuls sa classe et son code
     * (statut HTTP ou code SMTP, voir MailerTransportFailure) sont gardés.
     */
    public static function becauseTransportFailed(string $transportFailureClass, ?int $code): self
    {
        return new self(\sprintf(
            'L\'envoi du message de contact a échoué (%s, code %s).',
            $transportFailureClass,
            $code ?? 'inconnu',
        ));
    }
}
