<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * Exception métier levée par `App\Shared\Domain\ValueObject\RetentionPeriod`
 * lorsque l'expression relative fournie (typiquement l'option `--older-than`
 * d'une commande de purge) ne décrit pas une durée strictement positive, ou
 * n'est pas un intervalle relatif PHP lisible.
 */
final class InvalidRetentionPeriodException extends \DomainException
{
    /**
     * L'expression est syntaxiquement valide pour `\DateInterval` mais ne
     * produit pas un seuil strictement antérieur à l'instant présent (signe
     * négatif, durée nulle, ou expression déjà tournée vers le futur comme
     * "2 days ago").
     */
    public static function notStrictlyPositive(string $expression): self
    {
        return new self(sprintf(
            'La durée de rétention "%s" doit être strictement positive (le seuil calculé se situe dans le futur ou au moment présent).',
            $expression,
        ));
    }

    /**
     * L'expression est vide ou n'est pas un intervalle relatif PHP valide.
     */
    public static function unreadable(string $expression): self
    {
        return new self(sprintf(
            'La durée de rétention "%s" est illisible. Exemples valides : "30 days", "12 hours".',
            $expression,
        ));
    }
}
