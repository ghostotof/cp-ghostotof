<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Exception;

use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use Symfony\Component\Uid\Uuid;

/**
 * Un jeton de rafraîchissement déjà tourné a été présenté de nouveau, par son
 * client, sur une connexion encore active (OAuth 2.1 §4.3.1, #649).
 *
 * Un rafraîchissement n'est révoqué que par sa rotation — ou par son client
 * au point de révocation, qui révoque alors la connexion entière (RFC 7009
 * §2.1, décision 3 de #649) : sur une connexion active, un rafraîchissement
 * révoqué qui revient a donc forcément été tourné, et sa réutilisation se
 * reconnaît sans ambiguïté. Cause {@see RevocationCause::RefreshTokenReuse}.
 */
final class RotatedRefreshTokenReusedException extends CompromisedMcpConnectionException
{
    public static function forConnection(Uuid $connectionId): self
    {
        return new self('Un jeton de rafraîchissement déjà tourné a été présenté de nouveau : la connexion MCP est compromise.', $connectionId, RevocationCause::RefreshTokenReuse);
    }
}
