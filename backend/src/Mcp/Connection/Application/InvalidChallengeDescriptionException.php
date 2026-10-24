<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use InvalidArgumentException;

/**
 * Un `error_description` de défi Bearer hors de l'alphabet que RFC 6749 A.2
 * lui fixe (`%x20-21 / %x23-5B / %x5D-7E`, au moins un caractère), repris par
 * RFC 6750 §3 (#648). Un guillemet fermerait la chaîne entre guillemets de
 * RFC 7235, une barre oblique inverse l'échapperait, un retour à la ligne
 * couperait l'en-tête.
 *
 * La description est écrite par le serveur (l'outil nominatif, ADR 0006 D3),
 * jamais par le client : un refus est donc une faute du serveur, un 500
 * `critical`, sans entrée de journalisation propre. Le message ne cite pas
 * la valeur refusée.
 */
final class InvalidChallengeDescriptionException extends InvalidArgumentException
{
    public static function outsideTheRfcCharacterSet(): self
    {
        return new self('The error_description of a Bearer challenge must be 1 or more characters of %x20-21 / %x23-5B / %x5D-7E (RFC 6749 A.2).');
    }
}
