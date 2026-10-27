<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Exception;

use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use Symfony\Component\Uid\Uuid;

/**
 * Un code d'autorisation déjà échangé a été présenté de nouveau, sur une
 * connexion encore active (OAuth 2.1 §4.1.2, #649).
 *
 * Un code n'est révoqué que par son échange (usage unique, league le révoque
 * en dernier) : sur une connexion active, un code révoqué qui revient a donc
 * forcément été échangé, et son rejeu se reconnaît sans ambiguïté — l'un
 * après l'autre comme en concurrence, le jugement se faisant sous le verrou
 * de la connexion. Le code a fuité (la redirection interceptée), ou son
 * client rejoue une réponse perdue : la connexion meurt, et avec elle les
 * jetons que le code a émis. Cause {@see RevocationCause::CodeReplay}.
 */
final class AuthorizationCodeReplayedException extends CompromisedMcpConnectionException
{
    public static function forConnection(Uuid $connectionId): self
    {
        return new self('Un code d\'autorisation déjà échangé a été présenté de nouveau : la connexion MCP est compromise.', $connectionId, RevocationCause::CodeReplay);
    }
}
