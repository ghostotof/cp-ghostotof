<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Support;

use LogicException;

/**
 * Le test d'exposition symétrique ne sait pas lire le palier d'une règle
 * `access_control` (plusieurs rôles, un rôle inconnu, un attribut qui n'est
 * pas un rôle) : il échoue bruyamment plutôt que de sortir la route du sens 1.
 */
final class UnreadableAccessRuleException extends LogicException
{
    public static function forPath(string $path, string $cause): self
    {
        return new self(\sprintf('La règle access_control de %s %s : apprendre à ce test (et au lecteur de payload) à lire son palier.', $path, $cause));
    }
}
