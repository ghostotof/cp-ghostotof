<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Exception;

use App\Security\User\Domain\Entity\CpgUser;

/**
 * Exception métier levée lorsqu'un ROLE_SUPER tente de supprimer le dernier
 * compte disposant de ce rôle (point d'audit B9). Sans cette garde, le
 * backoffice deviendrait inaccessible et ne pourrait être récupéré qu'en
 * ligne de commande (app:user:create --role=ROLE_SUPER).
 */
final class CannotDeleteLastSuperAdminException extends \DomainException
{
    public static function forUsername(string $username): self
    {
        return new self(sprintf('Impossible de supprimer "%s" : c\'est le dernier compte %s, le backoffice deviendrait inaccessible.', $username, CpgUser::ROLE_SUPER));
    }
}
