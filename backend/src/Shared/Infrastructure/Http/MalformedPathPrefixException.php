<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

/**
 * `CanonicalPath::isUnder()` a reçu un préfixe vide, relatif ou terminé par
 * `/` (issue #383).
 *
 * Le préfixe est une valeur écrite dans le code (`/api/assistant`) : c'est une
 * erreur de programmation, qui sort en 500 `critical`, sans
 * `ProblemExceptionInterface` ni entrée `exception_to_status`. Acceptée, elle
 * échouerait sans bruit — `/api/x/` ne correspondrait plus à rien (une garde
 * désactivée), `` à tout.
 */
final class MalformedPathPrefixException extends \InvalidArgumentException
{
    public static function forPrefix(string $prefix): self
    {
        return new self(\sprintf('Préfixe de chemin mal formé : "%s" (attendu : absolu, sans "/" final).', $prefix));
    }
}
