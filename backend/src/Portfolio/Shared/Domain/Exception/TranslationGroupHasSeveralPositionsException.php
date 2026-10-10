<?php

declare(strict_types=1);

namespace App\Portfolio\Shared\Domain\Exception;

use Symfony\Component\Uid\Uuid;

/**
 * Spec 0004 D3 : toutes les entrées d'un groupe de traduction partagent sa
 * position, invariant tenu par ContentPlacement et OrderAssigner. Un groupe
 * dont les membres divergent est un défaut du pipeline, jamais une saisie
 * (issue #338).
 *
 * Elle sort donc en 500 `critical` : ni `ProblemExceptionInterface`, ni
 * entrée `exception_to_status`, et elle étend \LogicException plutôt que
 * \DomainException, que les erreurs clientes de ce dossier étendent. Le
 * message donne le groupe, ce qu'il faut retrouver en base.
 */
final class TranslationGroupHasSeveralPositionsException extends \LogicException
{
    public static function forGroup(Uuid $translationGroup): self
    {
        return new self(\sprintf('Le groupe de traduction %s porte plusieurs positions.', $translationGroup->toRfc4122()));
    }
}
