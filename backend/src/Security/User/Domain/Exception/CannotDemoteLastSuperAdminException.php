<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Exception;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Shared\Domain\Exception\HasProblemType;
use DomainException;

/**
 * Exception métier levée lorsqu'on tente de retirer le rôle ROLE_SUPER au
 * dernier compte qui le possède : sans cette garde, le backoffice deviendrait
 * inaccessible (récupérable seulement via app:user:create --role=ROLE_SUPER).
 * Pendant de CannotDeleteLastSuperAdminException pour la suppression.
 *
 * Message constant, qui ne cite aucun compte (issue #363) : le noyau et API
 * Platform le journalisent, et l'identifiant d'un invité est dérivé de son
 * adresse. L'appelant sait déjà de quel compte il s'agit ; le frontend lit le
 * statut (et le `type` quand il y en a un), jamais le `detail`.
 */
final class CannotDemoteLastSuperAdminException extends DomainException implements ProblemExceptionInterface
{
    use HasProblemType;

    public static function lastOne(): self
    {
        return new self('Impossible de retirer le rôle ROLE_SUPER à ce compte : c\'est le dernier à le posséder, le backoffice deviendrait inaccessible.');
    }

    protected function problemType(): string
    {
        return 'cannot-demote-last-super';
    }

    protected function problemStatus(): int
    {
        return 409;
    }
}
