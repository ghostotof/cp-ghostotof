<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use LogicException;

/**
 * `kernel.secret` (`APP_SECRET`) est vide : le MAC des enveloppes Messenger
 * (issue #444) serait calculable par quiconque écrit dans une file.
 * Défaut de configuration, donc une panne serveur, à la première
 * construction du sérialiseur : le premier message mis en file par l'API
 * (500), ou la première lecture d'une file par le worker.
 */
final class MissingEnvelopeKeyException extends LogicException
{
    public static function emptySecret(): self
    {
        return new self('MAC des enveloppes Messenger : kernel.secret (APP_SECRET) est vide.');
    }
}
