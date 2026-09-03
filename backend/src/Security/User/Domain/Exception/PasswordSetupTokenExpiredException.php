<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Exception;

/**
 * Le jeton de définition de mot de passe existe mais n'est plus utilisable :
 * expiré, ou déjà consommé. Les deux cas sont fusionnés pour ne pas révéler
 * qu'un lien a déjà servi.
 */
final class PasswordSetupTokenExpiredException extends \DomainException
{
    public static function expiredOrAlreadyUsed(): self
    {
        return new self('Ce lien de définition de mot de passe a expiré ou a déjà été utilisé.');
    }
}
