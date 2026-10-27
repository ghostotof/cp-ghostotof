<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\ValueObject\RevokedConnection;

/**
 * Ce que la transaction d'un accord a écrit (#647, #649), rendu à
 * ConsentService pour qu'il l'audite après le COMMIT : la redirection vers le
 * client, son code compris, et les connexions que l'accord a remplacées.
 * Interne au consentement, jamais persisté ni exposé.
 */
final readonly class WrittenGrant
{
    /**
     * @param string                  $location   la redirection que league a construite, code d'autorisation compris
     * @param list<RevokedConnection> $superseded les connexions actives du même couple compte/client que l'accord a révoquées
     */
    public function __construct(
        public string $location,
        public array $superseded,
    ) {
    }
}
