<?php

declare(strict_types=1);

namespace App\Shared\Presentation\ApiResource;

/**
 * Un DTO d'écriture contient une valeur que sa validation aurait dû refuser
 * (issue #338).
 *
 * Levée par les accesseurs qui rendent l'entrée sous la forme garantie par les
 * contraintes (`keys()`, `validatedCategory()`, `validatedFields()`) : quand on
 * les appelle, la validation a déjà tranché en 422. Une valeur qui la contredit
 * est un défaut du pipeline, jamais une saisie — 500 `critical`, sans
 * `ProblemExceptionInterface`, plutôt qu'un filtrage silencieux qui traiterait
 * une entrée amputée.
 */
final class UnvalidatedInputException extends \LogicException
{
    /**
     * @param string $what ce qui est passé à travers la validation, sans la valeur elle-même
     */
    public static function because(string $what): self
    {
        return new self(\sprintf('Entrée qui aurait dû être refusée par la validation : %s.', $what));
    }
}
