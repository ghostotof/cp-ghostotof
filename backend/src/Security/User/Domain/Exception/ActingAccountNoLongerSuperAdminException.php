<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Exception;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Shared\Domain\Exception\HasProblemType;
use DomainException;

/**
 * Le compte qui administre (supprime un compte, change un rôle) n'est plus un
 * ROLE_SUPER au moment où le verrou d'administration est pris (issue #551) :
 * rétrogradé ou supprimé par un autre super-administrateur entre le contrôle
 * du pare-feu, qui l'a laissé passer, et l'écriture. L'autorisation n'existe
 * plus : un 403, comme la requête suivante en recevra un du pare-feu. Rien
 * n'est écrit, rien n'est journalisé par le cas d'usage (un refus n'est pas
 * une action).
 *
 * Deux fabriques, deux messages constants, qui ne citent aucun compte
 * (issue #363) : le journal du noyau dit ainsi lequel des deux cas a refusé ;
 * la réponse porte le même statut et le même `type`, le frontend ne lit pas
 * le `detail`.
 */
final class ActingAccountNoLongerSuperAdminException extends DomainException implements ProblemExceptionInterface
{
    use HasProblemType;

    public static function demotedMeanwhile(): self
    {
        return new self('Le compte qui administre n\'a plus le rôle ROLE_SUPER : la demande est refusée.');
    }

    public static function deletedMeanwhile(): self
    {
        return new self('Le compte qui administre n\'existe plus : la demande est refusée.');
    }

    protected function problemType(): string
    {
        return 'acting-account-no-longer-super';
    }

    protected function problemStatus(): int
    {
        return 403;
    }
}
