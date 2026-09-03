<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Exception;

/**
 * Exception métier levée lorsque l'envoi effectif de l'e-mail d'invitation
 * échoue (SMTP indisponible, DSN mal configuré, etc.), dans
 * App\Security\User\Infrastructure\Messenger\SendAccountInvitationHandler.
 */
final class AccountInvitationDeliveryException extends \RuntimeException
{
    public static function becauseTransportFailed(\Throwable $previous): self
    {
        return new self('L\'envoi de l\'e-mail d\'invitation a échoué.', previous: $previous);
    }
}
