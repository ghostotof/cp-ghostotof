<?php

declare(strict_types=1);

namespace App\Portfolio\Shared\Domain\Exception;

use LogicException;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Spec 0004 D3 : toutes les entrées d'un groupe de traduction partagent sa
 * position, invariant tenu par ContentPlacement et OrderAssigner, et refusé en
 * base au COMMIT depuis l'issue #390. Un groupe dont les membres divergent est
 * un défaut du pipeline, jamais une saisie (issue #338).
 *
 * Elle sort donc en 500 `critical` : ni `ProblemExceptionInterface`, ni
 * entrée `exception_to_status`, et elle étend {@see LogicException} plutôt que
 * {@see \DomainException}, que les erreurs clientes de ce dossier étendent. Le
 * message donne le groupe, ce qu'il faut retrouver en base.
 */
final class TranslationGroupHasSeveralPositionsException extends LogicException
{
    public static function forGroup(Uuid $translationGroup): self
    {
        return new self(\sprintf('Le groupe de traduction %s porte plusieurs positions.', $translationGroup->toRfc4122()));
    }

    /**
     * La base a refusé au COMMIT une transaction qui laissait un groupe sur
     * plusieurs positions (issue #390). Son message, repris tel quel, nomme la
     * table et le groupe ; il ne contient rien que le client ait saisi.
     */
    public static function refusedAtCommit(Throwable $databaseRefusal): self
    {
        return new self(\sprintf('La base a refusé la transaction : %s', $databaseRefusal->getMessage()), 0, $databaseRefusal);
    }
}
