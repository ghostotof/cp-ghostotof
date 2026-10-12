<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\Doctrine;

use LogicException;

/**
 * Le verrou d'attribution des identifiants a été demandé dans une
 * transaction déjà ouverte (issue #532) : un défaut de l'appelant, jamais une
 * issue ordinaire.
 *
 * Imbriqué, le COMMIT du verrou ne serait qu'un `RELEASE SAVEPOINT` :
 * `CpgUserInviter::invite()`, qui publie l'invitation au retour du verrou en
 * le tenant pour le COMMIT, la publierait avant que le compte soit visible ;
 * le worker n'en trouverait aucun et abandonnerait le message sans réessai.
 * Le verrou, lui, tiendrait toutes les invitations jusqu'au COMMIT de
 * l'appelant. D'où un refus avant de prendre le verrou, sans mapping HTTP :
 * un 500 `critical` qui porte un nom. Le message ne cite rien de la requête.
 */
final class UsernameAllocationLockInsideTransactionException extends LogicException
{
    public static function create(): self
    {
        return new self('Le verrou d\'attribution des identifiants ne se prend pas dans une transaction déjà ouverte : son COMMIT ne serait qu\'un point de sauvegarde, et l\'invitation serait publiée avant la validation du compte.');
    }
}
