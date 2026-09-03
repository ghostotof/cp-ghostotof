<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Exception;

use App\Security\User\Domain\Entity\CpgUser;

/**
 * Exception métier levée lorsqu'on tente de retirer le rôle ROLE_SUPER au
 * dernier compte qui le possède : sans cette garde, le backoffice deviendrait
 * inaccessible (récupérable seulement via app:user:create --role=ROLE_SUPER).
 * Pendant de CannotDeleteLastSuperAdminException pour la suppression.
 */
final class CannotDemoteLastSuperAdminException extends \DomainException
{
    public static function forUsername(string $username): self
    {
        return new self(sprintf('Impossible de retirer le rôle %s à "%s" : c\'est le dernier compte à le posséder, le backoffice deviendrait inaccessible.', CpgUser::ROLE_SUPER, $username));
    }
}
