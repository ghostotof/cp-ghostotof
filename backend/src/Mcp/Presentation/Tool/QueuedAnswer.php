<?php

declare(strict_types=1);

namespace App\Mcp\Presentation\Tool;

use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Result\CallToolResult;

/**
 * Ce que pèse, dans la session MCP, la réponse d'un outil (#648) : le SDK
 * 0.8.1 met toute réponse en file dans la session avant de la rendre
 * (`Protocol::queueOutgoing()`), encodée par un `json_encode` sans drapeau
 * de la réponse JSON-RPC, puis `Session::save()` réencode la session entière,
 * file comprise, par un second `json_encode` sans drapeau — chaque guillemet,
 * barre oblique ou échappement de la première passe s'y double. Au-delà de
 * {@see \App\Mcp\Infrastructure\Session\BoundedSessionStore::MAX_BYTES}, la
 * session est détruite et le client ne reçoit rien.
 *
 * Reproduit ici avec les classes publiques du SDK (la réponse JSON-RPC
 * qu'il sérialise) et les deux encodages, sous l'identifiant `0` : celui du
 * client, à un octet près, est hors de la mesure. Les deux encodages sont des
 * détails internes du SDK qu'aucune API ne publie ;
 * GetNominativeCorpusToolTest compare cette mesure à ce que le SDK met
 * réellement en file, et rougit le jour où une montée de version change
 * l'encodage.
 */
final class QueuedAnswer
{
    /** Octets que `$result` occupe dans la file de la session, une fois la session encodée. */
    public static function bytes(CallToolResult $result): int
    {
        $answer = json_encode(new Response(0, $result), \JSON_THROW_ON_ERROR);

        return \strlen(json_encode($answer, \JSON_THROW_ON_ERROR));
    }
}
