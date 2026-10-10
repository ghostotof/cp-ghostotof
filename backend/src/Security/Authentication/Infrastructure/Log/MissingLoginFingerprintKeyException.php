<?php

declare(strict_types=1);

namespace App\Security\Authentication\Infrastructure\Log;

use LogicException;

/**
 * `kernel.secret` (`APP_SECRET`) est vide : l'empreinte de l'identifiant
 * tenté au login (issue #418) ne serait plus qu'un SHA-256 qu'un dictionnaire
 * renverse sans aucun secret. Défaut de configuration, donc une panne
 * serveur (500 `critical`), jamais une erreur du client.
 */
final class MissingLoginFingerprintKeyException extends LogicException
{
    public static function emptySecret(): self
    {
        return new self('Empreinte de l\'identifiant tenté : kernel.secret (APP_SECRET) est vide.');
    }
}
