<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Exception;

use RuntimeException;

/**
 * Exception métier levée lorsque l'e-mail d'invitation ne part pas, dans
 * {@see \App\Security\User\Infrastructure\Messenger\SendAccountInvitationHandler} :
 * transport en échec (SMTP indisponible, DSN mal configuré, etc.) ou toute
 * autre défaillance de l'envoi, en pratique le rendu du gabarit (issue #419).
 * Dans les deux cas, ni l'exception d'origine ni son message.
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

    /**
     * Toute autre défaillance de l'envoi, en pratique le rendu Twig du
     * gabarit (issue #419). Même règle que pour le transport, pour une autre
     * raison : le jeton d'invitation est déjà valide en base quand le gabarit
     * est rendu, et un message Twig peut citer une valeur du contexte
     * (`Key "https://…/set-password#<jeton>" … does not exist`). Le stamp
     * d'erreur que le worker pose garde le message et toute la chaîne
     * `previous` jusque dans le failure_transport (audit C2) : seule la
     * description sûre de l'échec est gardée, ni son message ni l'exception
     * elle-même.
     *
     * @param string $failure le type de l'échec et, pour une erreur de gabarit,
     *                        `<gabarit>:<ligne>` — jamais son message
     */
    public static function becauseSendingFailed(string $failure): self
    {
        return new self(\sprintf(
            'La préparation ou l\'envoi de l\'e-mail d\'invitation a échoué (%s).',
            $failure,
        ));
    }
}
