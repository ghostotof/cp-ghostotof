<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\Doctrine;

use RuntimeException;
use Throwable;

/**
 * Le verrou d'administration des comptes (issue #551) — l'advisory lock
 * global, ou la ligne du compte administré — est resté pris au-delà de
 * `lock_timeout`. Ses détenteurs le gardent quelques millisecondes : au-delà,
 * c'est une anomalie, pas un conflit à réessayer côté HTTP — une panne
 * serveur nommée (500 `critical`), comme pour les autres verrous du squelette.
 * Le message ne cite que l'UUID du compte administré.
 */
final class AccountAdministrationLockTimeoutException extends RuntimeException
{
    public static function forAccount(string $userId, Throwable $previous): self
    {
        return new self(\sprintf('Le verrou d\'administration du compte %s est resté pris au-delà de lock_timeout.', $userId), 0, $previous);
    }
}
