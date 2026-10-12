<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\Doctrine;

use LogicException;

/**
 * Le verrou d'un compte a été demandé dans une transaction déjà ouverte
 * (issue #532) : un défaut de l'appelant, jamais une issue ordinaire.
 *
 * Imbriqué, le COMMIT du verrou ne serait qu'un `RELEASE SAVEPOINT` : le
 * `FOR UPDATE` du compte tiendrait jusqu'au COMMIT de l'appelant, et ce que
 * l'opération fait au retour du verrou — le handler d'invitation envoie son
 * lien — partirait avant que le jeton soit validé : un clic rapide tomberait
 * sur un lien inconnu. D'où un refus avant de verrouiller la ligne, sans
 * mapping HTTP : un 500 `critical` qui porte un nom ; côté worker, le message
 * suit les réessais de Messenger puis le `failure_transport` (voir les
 * limites de PostgresLockedTransaction pour une transaction laissée ouverte
 * sur la connexion du worker). Le message ne
 * cite que l'UUID du compte, jamais son identifiant (issue #363).
 */
final class AccountLockInsideTransactionException extends LogicException
{
    public static function forAccount(string $userId): self
    {
        return new self(\sprintf('Le verrou du compte %s ne se prend pas dans une transaction déjà ouverte : son COMMIT ne serait qu\'un point de sauvegarde, et ce qui suit le verrou partirait avant la validation.', $userId));
    }
}
