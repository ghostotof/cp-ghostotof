<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\Doctrine;

use UnexpectedValueException;

/**
 * La ligne que rend la révocation d'une connexion MCP (#649, `RETURNING`)
 * n'a pas la forme du schéma : une colonne absente ou d'un autre type que
 * texte. Un défaut du serveur (schéma ou pilote), jamais d'un client : aucune
 * entrée `framework.exceptions`, un 500 `critical`. Le message nomme la
 * colonne, jamais sa valeur.
 */
final class UnexpectedRevocationRowException extends UnexpectedValueException
{
    public static function forColumn(string $column): self
    {
        return new self(\sprintf('La révocation d\'une connexion MCP a rendu une colonne « %s » absente ou non textuelle.', $column));
    }
}
