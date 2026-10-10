<?php

declare(strict_types=1);

namespace App\Security\Authentication\Infrastructure\Http;

use InvalidArgumentException;

/**
 * `AuthCookieFactory::expired()` a reçu un nom qui n'est ni `BEARER` ni
 * `XSRF-TOKEN` (issue #383).
 *
 * Erreur de programmation : le seul appelant, CookieLogoutListener, passe les
 * deux constantes. Elle sort en 500 `critical`, sans `ProblemExceptionInterface` ni
 * entrée `exception_to_status`, plutôt que d'expirer un cookie qui n'est pas
 * celui de la fabrique.
 */
final class UnknownAuthCookieException extends InvalidArgumentException
{
    /**
     * Un `token` de la RFC 6265 (§ 4.1.1), borné à 64 caractères. Un nom
     * construit dynamiquement peut venir d'une requête : ce qui s'écarte de
     * cette forme n'est pas repris dans le message, donc dans les journaux.
     * `\z` et non `$`, qui accepterait aussi un `\n` final.
     */
    private const string QUOTABLE_NAME = '/^[A-Za-z0-9!#$%&\'*+.^_`|~-]{1,64}\z/';

    public static function forName(string $name): self
    {
        return new self(\sprintf(
            '"%s" n\'est pas un cookie d\'authentification (attendu : %s ou %s).',
            1 === preg_match(self::QUOTABLE_NAME, $name) ? $name : '<invalide>',
            AuthCookieFactory::BEARER,
            AuthCookieFactory::XSRF_TOKEN,
        ));
    }
}
