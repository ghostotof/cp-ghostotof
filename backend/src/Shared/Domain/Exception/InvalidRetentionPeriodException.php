<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

use DomainException;

/**
 * Exception métier levée par {@see \App\Shared\Domain\ValueObject\RetentionPeriod}
 * lorsque l'expression relative fournie (typiquement l'option `--older-than`
 * d'une commande de purge) ne décrit pas une durée strictement positive, ou
 * n'est pas un intervalle relatif PHP lisible.
 */
final class InvalidRetentionPeriodException extends DomainException
{
    /**
     * L'expression est syntaxiquement valide pour {@see \DateInterval} mais ne
     * produit pas un seuil strictement antérieur à l'instant présent (signe
     * négatif, durée nulle, ou expression déjà tournée vers le futur comme
     * "2 days ago").
     */
    public static function notStrictlyPositive(string $expression): self
    {
        return new self(\sprintf(
            'La durée de rétention "%s" doit être strictement positive (le seuil calculé se situe dans le futur ou au moment présent).',
            $expression,
        ));
    }

    /**
     * L'expression est lisible mais désigne une date plutôt qu'une durée :
     * jour de la semaine ("monday", "3 weekdays"), "first/last … of", heure
     * du jour ("noon tomorrow", "yesterday" qui ramène à minuit). Issue #710.
     */
    public static function designatesADate(string $expression): self
    {
        return new self(\sprintf(
            'La durée de rétention "%s" désigne une date (jour de la semaine, "first/last … of", heure du jour), pas une durée. Exemples valides : "30 days", "12 hours".',
            $expression,
        ));
    }

    /**
     * La durée place le seuil avant l'an 1, que PostgreSQL ne sait pas lire
     * ("5000 years"). Relecture de #710.
     */
    public static function tooLong(string $expression): self
    {
        return new self(\sprintf(
            'La durée de rétention "%s" est trop longue : le seuil calculé tombe avant l\'an 1.',
            $expression,
        ));
    }

    /**
     * L'expression est vide ou n'est pas un intervalle relatif PHP valide.
     */
    public static function unreadable(string $expression): self
    {
        return new self(\sprintf(
            'La durée de rétention "%s" est illisible. Exemples valides : "30 days", "12 hours".',
            $expression,
        ));
    }
}
