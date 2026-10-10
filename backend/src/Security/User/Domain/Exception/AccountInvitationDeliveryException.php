<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Exception;

use RuntimeException;

/**
 * Exception métier levée lorsque l'envoi effectif de l'e-mail d'invitation
 * échoue (SMTP indisponible, DSN mal configuré, etc.), dans
 * {@see \App\Security\User\Infrastructure\Messenger\SendAccountInvitationHandler}.
 */
final class AccountInvitationDeliveryException extends RuntimeException
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
            'L\'envoi de l\'e-mail d\'invitation a échoué (%s, code %s).',
            $transportFailureClass,
            $code ?? 'inconnu',
        ));
    }
}
