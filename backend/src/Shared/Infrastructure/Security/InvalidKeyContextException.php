<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use InvalidArgumentException;

/**
 * Un contexte de clé hors du format `cp-ghostotof/<usage>/v<n>` (issue #487) :
 * un contexte vide, ou celui d'un autre usage écrit autrement, donnerait une
 * clé que rien ne sépare des autres. Défaut de code, donc une panne serveur
 * (500 `critical`). Le contexte est une constante du code, jamais une saisie :
 * le message peut le citer.
 */
final class InvalidKeyContextException extends InvalidArgumentException
{
    public static function forContext(string $context): self
    {
        return new self(\sprintf('Contexte de clé « %s » hors du format cp-ghostotof/<usage>/v<n>.', $context));
    }
}
