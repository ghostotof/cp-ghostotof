<?php

declare(strict_types=1);

namespace App\Portfolio\Experience\Infrastructure\Doctrine;

use LogicException;

/**
 * Le verrou d'une technologie a été demandé dans une transaction déjà ouverte
 * (issue #545, règle commune des verrous du squelette depuis #532) : un défaut
 * de l'appelant, jamais une issue ordinaire.
 *
 * Imbriqué, le COMMIT du verrou ne serait qu'un `RELEASE SAVEPOINT` : la ligne
 * resterait tenue jusqu'au COMMIT de l'appelant, et un refus de ce COMMIT-là
 * tomberait hors du verrou. D'où un refus avant de verrouiller la ligne, sans
 * mapping HTTP : un 500 `critical` qui porte un nom. Le message ne cite que
 * l'UUID.
 */
final class ExperienceTechnologyLockInsideTransactionException extends LogicException
{
    public static function forTechnology(string $technologyId): self
    {
        return new self(\sprintf('Le verrou de la technologie %s ne se prend pas dans une transaction déjà ouverte : son COMMIT ne serait qu\'un point de sauvegarde.', $technologyId));
    }
}
