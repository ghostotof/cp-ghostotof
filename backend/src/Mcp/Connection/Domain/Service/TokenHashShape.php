<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Service;

use App\Mcp\Connection\Domain\Exception\MalformedTokenHashException;

/**
 * La forme d'une empreinte de code ou de jeton de connexion MCP, écrite une
 * fois pour les trois entités qui en stockent une : ce que rend
 * `hash('sha256', $identifiant)`, 64 caractères hexadécimaux minuscules.
 *
 * Refuser à la construction plutôt que compter sur la colonne `CHAR(64)` :
 * un identifiant en clair de 64 caractères y entrerait, et la recherche par
 * empreinte d'un jeton présenté ne le retrouverait jamais — un jeton en clair
 * en base, et muet. La casse compte pour la même raison : `hash()` rend des
 * minuscules, une empreinte en majuscules ne serait jamais retrouvée.
 */
final class TokenHashShape
{
    private const string PATTERN = '/\A[0-9a-f]{64}\z/';

    private function __construct()
    {
    }

    /**
     * @throws MalformedTokenHashException quand `$hash` n'a pas la forme d'un SHA-256 hexadécimal minuscule
     */
    public static function assertWellFormed(string $hash): void
    {
        if (1 !== preg_match(self::PATTERN, $hash)) {
            throw MalformedTokenHashException::notALowercaseSha256HexDigest();
        }
    }
}
