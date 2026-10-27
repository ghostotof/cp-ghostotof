<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use App\Mcp\Connection\Domain\Entity\McpAuthorizationCode;
use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\Entity\McpRefreshToken;

/**
 * Un code ou un rafraîchissement que {@see TokenUnderConnectionLock::judge()}
 * n'a pas dit mort (#649) : sa connexion, verrouillée `FOR UPDATE` et active,
 * et le jeton relu sous ce verrou, non révoqué. Le dépôt de league qui l'a
 * demandé poursuit son jugement sur ces deux instances-là, jamais sur celles
 * d'avant le verrou.
 *
 * @template T of McpAuthorizationCode|McpRefreshToken
 */
final readonly class LockedToken
{
    /**
     * @param T $token
     */
    public function __construct(
        public McpConnection $connection,
        public McpAuthorizationCode|McpRefreshToken $token,
    ) {
    }
}
