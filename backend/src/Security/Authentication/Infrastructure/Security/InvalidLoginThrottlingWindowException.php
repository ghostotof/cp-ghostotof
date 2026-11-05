<?php

declare(strict_types=1);

namespace App\Security\Authentication\Infrastructure\Security;

use LogicException;

/**
 * La fenêtre de `login_throttling` reçue par {@see LoginThrottlingRefusalListener}
 * n'est pas une durée : le plafond du `Retry-After` serait nul ou négatif, et
 * tout refus de connexion annoncerait un délai qui n'a pas de sens.
 * Défaut de configuration (le paramètre `app.login_throttling_interval_minutes`),
 * donc une panne serveur (500 `critical`), jamais une erreur du client.
 */
final class InvalidLoginThrottlingWindowException extends LogicException
{
    public static function forMinutes(int $minutes): self
    {
        return new self(\sprintf('La fenêtre de login_throttling doit durer au moins une minute, %d reçue.', $minutes));
    }
}
