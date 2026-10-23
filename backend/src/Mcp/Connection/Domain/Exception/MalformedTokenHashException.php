<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Exception;

use InvalidArgumentException;

/**
 * Un code ou un jeton de connexion MCP a reçu, à la place de son empreinte,
 * autre chose qu'un SHA-256 hexadécimal minuscule — le plus souvent
 * l'identifiant en clair, que la base aurait gardé. Défaut de câblage, jamais
 * une erreur client : aucune entrée `framework.exceptions`, un 500 `critical`.
 *
 * Le message ne cite jamais la valeur reçue : ce peut être un jeton en clair.
 */
final class MalformedTokenHashException extends InvalidArgumentException
{
    public static function notALowercaseSha256HexDigest(): self
    {
        return new self("L'empreinte d'un code ou d'un jeton de connexion MCP doit être un SHA-256 en hexadécimal minuscule (64 caractères).");
    }
}
