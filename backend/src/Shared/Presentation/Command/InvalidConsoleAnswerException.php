<?php

declare(strict_types=1);

namespace App\Shared\Presentation\Command;

/**
 * Une réponse à une question console est refusée par le validateur de la
 * question (issue #383).
 *
 * Elle ne quitte jamais la commande : le QuestionHelper rattrape toute
 * `\Exception` d'un validateur, en affiche le message à l'opérateur et repose
 * la question. Le message est donc une consigne de saisie, jamais une trace.
 * Réservée aux refus sans équivalent métier : quand le domaine a déjà une
 * exception pour la règle (InvalidUsernameException,
 * InvalidExperienceYearsException), le validateur lève celle-là.
 */
final class InvalidConsoleAnswerException extends \InvalidArgumentException
{
    /**
     * @param string $subject ce qui est demandé, avec son article (« Le mot de passe »)
     */
    public static function empty(string $subject): self
    {
        return new self(\sprintf('%s ne peut pas être vide.', $subject));
    }
}
