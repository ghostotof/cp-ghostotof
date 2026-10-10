<?php

declare(strict_types=1);

namespace App\Shared\Presentation\Command;

use Exception;
use InvalidArgumentException;

/**
 * Une réponse à une question console est refusée par le validateur de la
 * question (issue #383).
 *
 * Le QuestionHelper rattrape toute {@see Exception} d'un validateur, en affiche le
 * message à l'opérateur et repose la question. Sur une fin d'entrée, il relance
 * la dernière : la commande doit donc la rattraper autour de `ask()`, sans quoi
 * elle sort et le ErrorListener de la console la journalise en `critical`. Le
 * message est une consigne de saisie, et ne cite jamais la réponse.
 * Réservée aux refus sans équivalent métier : quand le domaine a déjà une
 * exception pour la règle (InvalidUsernameException,
 * InvalidExperienceYearsException), le validateur lève celle-là.
 */
final class InvalidConsoleAnswerException extends InvalidArgumentException
{
    /**
     * @param string $subject ce qui est demandé, avec son article (« Le mot de passe »)
     */
    public static function empty(string $subject): self
    {
        return new self(\sprintf('%s ne peut pas être vide.', $subject));
    }
}
