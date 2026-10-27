<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use LogicException;

/**
 * La cause `superseded` demandée à McpConnectionRevokerInterface::revoke()
 * (#726, constat B3) : un défaut du programme, jamais une condition
 * d'exécution.
 *
 * Le remplacement a son seul chemin, le premier échange du code d'un nouvel
 * accord (TokenEndpoint, CodeExchangeSupersessionInterface) : il révoque les
 * autres connexions du couple dans la transaction de l'échange et écrit leur
 * audit après le COMMIT, chaque ligne nommant la connexion qui remplace
 * (`supersededBy`). Passé par le révocateur, le remplacement aurait été
 * audité sans ce champ — la coupure n'aurait plus été reliée à l'accord qui
 * l'a causée.
 *
 * Levée avant toute écriture ; non mappée, un 500 `critical`. Son message est
 * constant, sans UUID.
 */
final class RevocationCauseReservedToTheExchangeException extends LogicException
{
    public static function superseded(): self
    {
        return new self('La cause « superseded » est réservée au premier échange du code d\'un nouvel accord : le révocateur ne l\'écrit pas.');
    }
}
