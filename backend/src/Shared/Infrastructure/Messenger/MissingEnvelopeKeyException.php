<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use LogicException;

/**
 * `kernel.secret` (`APP_SECRET`) est vide : le MAC des enveloppes Messenger
 * (issue #444) serait calculable par quiconque écrit dans une file.
 * Défaut de configuration, donc une panne serveur, au démarrage du worker
 * comme de l'API.
 */
final class MissingEnvelopeKeyException extends LogicException
{
    public static function emptySecret(): self
    {
        return new self('MAC des enveloppes Messenger : kernel.secret (APP_SECRET) est vide.');
    }
}
