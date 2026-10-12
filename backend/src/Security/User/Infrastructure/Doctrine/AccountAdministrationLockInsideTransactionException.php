<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\Doctrine;

use LogicException;

/**
 * Le verrou d'administration des comptes (issue #551) a été demandé dans une
 * transaction déjà ouverte (issue #532) : un défaut de l'appelant, jamais une
 * issue ordinaire.
 *
 * Imbriqué, le COMMIT du verrou ne serait qu'un `RELEASE SAVEPOINT` : le
 * verrou global et la ligne tiendraient jusqu'au COMMIT de l'appelant, et le
 * journal de sécurité, écrit au retour du verrou, dirait validée une
 * suppression ou un changement de rôle que ce COMMIT peut encore annuler.
 * D'où un refus avant de rien prendre, sans mapping HTTP : un 500 `critical`
 * qui porte un nom. Le message ne cite que l'UUID du compte administré, jamais
 * son identifiant (issue #363).
 */
final class AccountAdministrationLockInsideTransactionException extends LogicException
{
    public static function forAccount(string $userId): self
    {
        return new self(\sprintf('Le verrou d\'administration du compte %s ne se prend pas dans une transaction déjà ouverte : son COMMIT ne serait qu\'un point de sauvegarde, et le journal dirait validé ce qui ne l\'est pas.', $userId));
    }
}
