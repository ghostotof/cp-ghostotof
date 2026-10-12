<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\Doctrine;

use RuntimeException;
use Throwable;

/**
 * Le verrou d'attribution des identifiants (issue #364) est resté pris
 * au-delà de `lock_timeout`. Ses détenteurs le gardent le temps d'une
 * invitation, quelques millisecondes : au-delà, c'est une anomalie, pas un
 * conflit à réessayer — une panne serveur nommée (500 `critical`), comme pour
 * le verrou d'un compte (#435) et celui de périmètre d'ordre (#389). Le
 * message ne cite rien de la requête.
 */
final class UsernameAllocationLockTimeoutException extends RuntimeException
{
    public static function afterWaiting(Throwable $previous): self
    {
        return new self('Le verrou d\'attribution des identifiants est resté pris au-delà de lock_timeout.', 0, $previous);
    }
}
