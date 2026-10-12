<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use RuntimeException;

/**
 * Le worker n'a pas pu poser la marque du message témoin (issue #470) : le
 * cache en base a refusé l'écriture. Panne serveur, réessayée par Messenger ;
 * le message ne cite que la cause.
 */
final class ProbeMarkNotSavedException extends RuntimeException
{
    public static function forProbe(): self
    {
        return new self('Marque du message témoin non enregistrée : le cache en base a refusé l\'écriture (issue #470).');
    }
}
