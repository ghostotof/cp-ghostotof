<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use LogicException;

/**
 * `kernel.secret` (`APP_SECRET`) est vide : toute clé qui en dériverait serait
 * calculable par quiconque. Défaut de configuration, donc une panne serveur
 * (500 `critical`), jamais une erreur du client. Le message nomme l'usage,
 * jamais une valeur.
 */
final class EmptyApplicationSecretException extends LogicException
{
    public static function forContext(string $context): self
    {
        return new self(\sprintf('Clé « %s » : kernel.secret (APP_SECRET) est vide.', $context));
    }
}
