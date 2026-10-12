<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Exception;

use DomainException;

/**
 * Exception métier levée lorsqu'un ROLE_SUPER tente de supprimer le dernier
 * compte disposant de ce rôle (point d'audit B9). Sans cette garde, le
 * backoffice deviendrait inaccessible et ne pourrait être récupéré qu'en
 * ligne de commande (app:user:create --role=ROLE_SUPER).
 *
 * Message constant, qui ne cite aucun compte (issue #363) : le noyau et API
 * Platform le journalisent, et l'identifiant d'un invité est dérivé de son
 * adresse. L'appelant sait déjà de quel compte il s'agit ; le frontend lit le
 * statut (et le `type` quand il y en a un), jamais le `detail`.
 */
final class CannotDeleteLastSuperAdminException extends DomainException
{
    public static function lastOne(): self
    {
        return new self('Impossible de supprimer ce compte : c\'est le dernier compte ROLE_SUPER, le backoffice deviendrait inaccessible.');
    }
}
