<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\ValueObject\RevokedConnection;
use Psr\Http\Message\ResponseInterface;

/**
 * Ce que la transaction du point de jeton a écrit (#726), rendu à
 * TokenEndpoint pour qu'il l'audite après le COMMIT : la réponse de league,
 * jetons compris, et les connexions que le premier échange du code a
 * remplacées. Interne au point de jeton, jamais persisté ni exposé.
 */
final readonly class IssuedTokens
{
    /**
     * @param ResponseInterface       $response   la réponse de league, celle que le client reçoit
     * @param list<RevokedConnection> $superseded les autres connexions actives du même couple compte/client que l'échange a révoquées
     */
    public function __construct(
        public ResponseInterface $response,
        public array $superseded,
    ) {
    }
}
