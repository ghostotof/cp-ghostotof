<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use LogicException;

/**
 * La cause `expired` demandée à McpConnectionRevokerInterface::revoke() (lot C
 * des relectures de #649) : un défaut du programme, jamais une condition
 * d'exécution.
 *
 * L'expiration a son seul chemin, la purge planifiée (McpConnectionPurger) :
 * elle juge l'échéance sous le verrou des connexions, les révoque dans sa
 * transaction et écrit leur audit après le COMMIT, acteur `system`. Le
 * révocateur, lui, révoque hors transaction une connexion compromise au point
 * de jeton, et l'audite avec l'acteur lu dans le jeton de sécurité : une
 * expiration passée par lui aurait été auditée comme l'acte d'un appelant.
 *
 * Levée avant toute écriture ; non mappée, un 500 `critical`. Son message est
 * constant, sans UUID.
 */
final class RevocationCauseReservedToThePurgeException extends LogicException
{
    public static function expired(): self
    {
        return new self('La cause « expired » est réservée à la purge des connexions MCP : le révocateur ne l\'écrit pas.');
    }
}
