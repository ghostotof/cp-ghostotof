<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use SensitiveParameter;

/**
 * L'empreinte sous laquelle un code ou un jeton de league est stocké et
 * retrouvé (#647) : SHA-256 hexadécimal de son identifiant, la forme que
 * {@see \App\Mcp\Connection\Domain\Service\TokenHashShape} exige. Écrite une fois pour les trois
 * dépôts : une empreinte calculée autrement à l'écriture qu'à la lecture ne
 * retrouverait jamais son jeton.
 *
 * Un SHA-256 sans clé suffit : l'identifiant est tiré de 40 octets aléatoires,
 * rien à deviner par dictionnaire.
 */
final class TokenIdentifierHash
{
    private function __construct()
    {
    }

    public static function of(#[SensitiveParameter] string $identifier): string
    {
        return hash('sha256', $identifier);
    }
}
