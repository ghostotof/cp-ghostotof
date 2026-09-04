<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Exception;

/**
 * Le jeton de définition de mot de passe fourni ne correspond à aucun jeton
 * connu. Le message reste volontairement vague (aucune valeur de jeton, aucune
 * indication d'existence) pour ne rien révéler à un appelant anonyme.
 */
final class InvalidPasswordSetupTokenException extends \DomainException
{
    public static function unknownToken(): self
    {
        return new self('Lien de définition de mot de passe invalide.');
    }
}
