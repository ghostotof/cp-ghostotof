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
 * Une fabrique par attente, pour que le journal dise laquelle a dépassé : une
 * autre administration qui dure (l'advisory lock), ou la ligne tenue par le
 * handler d'invitation, la purge ou un changement de mot de passe. Le message
 * ne cite que l'UUID du compte administré.
 */
final class AccountAdministrationLockTimeoutException extends RuntimeException
{
    public static function advisoryLockHeld(string $userId, Throwable $previous): self
    {
        return new self(\sprintf('Le verrou global d\'administration des comptes est resté pris au-delà de lock_timeout (administration du compte %s).', $userId), 0, $previous);
    }

    public static function rowHeld(string $userId, Throwable $previous): self
    {
        return new self(\sprintf('La ligne du compte %s est restée verrouillée au-delà de lock_timeout pendant son administration.', $userId), 0, $previous);
    }
}
