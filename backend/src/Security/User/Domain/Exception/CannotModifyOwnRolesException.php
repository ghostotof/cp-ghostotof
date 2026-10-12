<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Exception;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Shared\Domain\Exception\HasProblemType;
use DomainException;

/**
 * Exception métier levée lorsqu'un ROLE_SUPER tente de modifier ses propres
 * rôles depuis le backoffice (typiquement se rétrograder) : garde anti-lockout
 * accidentel, sur le même modèle que CannotDeleteOwnAccountException.
 *
 * Message constant, qui ne cite aucun compte (issue #363) : le noyau et API
 * Platform le journalisent, et l'identifiant d'un invité est dérivé de son
 * adresse. L'appelant sait déjà de quel compte il s'agit ; le frontend lit le
 * statut (et le `type` quand il y en a un), jamais le `detail`.
 */
final class CannotModifyOwnRolesException extends DomainException implements ProblemExceptionInterface
{
    use HasProblemType;

    public static function forActingAccount(): self
    {
        return new self('Un compte ne peut pas modifier ses propres rôles.');
    }

    protected function problemType(): string
    {
        return 'cannot-modify-own-roles';
    }

    protected function problemStatus(): int
    {
        return 409;
    }
}
