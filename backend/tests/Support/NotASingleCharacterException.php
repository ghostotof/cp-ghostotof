<?php

declare(strict_types=1);

namespace App\Tests\Support;

use InvalidArgumentException;

/**
 * {@see PhpSources::isChar()} ou {@see PhpSources::isAnyChar()} a reçu un
 * argument qui n'est pas un seul caractère (#748, St17-31 et R220b de la
 * dix-septième relecture).
 *
 * L'argument est écrit dans le code d'un recenseur (`'('`, `','`) : c'est une
 * erreur de programmation. Acceptée, elle échouerait sans bruit — seul le
 * premier octet est lu, `'(('` passerait pour `'('`. Une exception plutôt
 * qu'un `\assert()`, que `zend.assertions=-1` (le `php.ini-production` que
 * setup-php charge en CI) retire du code compilé : le contrat tient partout.
 */
final class NotASingleCharacterException extends InvalidArgumentException
{
    public static function forArgument(string $argument): self
    {
        return new self(\sprintf('Un seul caractère attendu, reçu %d octet(s) : %s.', \strlen($argument), var_export($argument, true)));
    }
}
