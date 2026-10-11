<?php

declare(strict_types=1);

namespace App\Contact\Domain\Exception;

use RuntimeException;

/**
 * Exception métier levée lorsque l'email de contact ne part pas, dans
 * {@see \App\Contact\Infrastructure\Messenger\SendContactMessageHandler} :
 * transport en échec (SMTP indisponible, DSN mal configuré, etc.) ou toute
 * autre défaillance de la construction ou de l'envoi (issue #427). Dans les
 * deux cas, ni l'exception d'origine ni son message.
 */
final class ContactMessageDeliveryException extends RuntimeException
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

    /**
     * Toute autre défaillance : le rendu Twig du gabarit, une adresse que Mime
     * refuse (issue #427). Leur message peut citer ce que le visiteur a saisi
     * (son adresse, son nom, son texte), et le stamp d'erreur du worker garde
     * le message et toute la chaîne `previous` jusque dans le failure_transport :
     * seule la description sûre de l'échec est gardée.
     *
     * @param string $failure le type de l'échec et `<gabarit>:<ligne>` ou
     *                        `<fichier>:<ligne>` — jamais son message
     */
    public static function becauseSendingFailed(string $failure): self
    {
        return new self(\sprintf(
            'La préparation ou l\'envoi du message de contact a échoué (%s).',
            $failure,
        ));
    }
}
