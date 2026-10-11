<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\Doctrine;

use RuntimeException;
use Throwable;

/**
 * Le verrou d'un compte (issues #435, #458) est resté pris au-delà de
 * `lock_timeout`. Ses détenteurs le gardent quelques millisecondes : au-delà,
 * c'est une anomalie, pas un conflit à réessayer côté HTTP — une panne
 * serveur nommée (500 `critical`), comme pour le verrou de périmètre d'ordre
 * (#389). Côté worker, le réessai de Messenger reprend. Le message ne cite
 * que l'identifiant du compte.
 */
final class AccountLockTimeoutException extends RuntimeException
{
    public static function forAccount(string $userId, Throwable $previous): self
    {
        return new self(\sprintf('Le compte %s est resté verrouillé au-delà de lock_timeout.', $userId), 0, $previous);
    }
}
