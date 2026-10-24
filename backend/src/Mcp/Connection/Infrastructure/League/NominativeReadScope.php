<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use App\Mcp\Connection\Domain\Entity\McpConnection;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\Traits\ScopeTrait;

/**
 * Le seul scope du serveur d'autorisation, vu par league : celui que le
 * domaine définit (McpConnection::SCOPE_NOMINATIVE_READ). Rien d'autre ne
 * s'accorde, ni ne se demande.
 */
final class NominativeReadScope implements ScopeEntityInterface
{
    use ScopeTrait;

    public const string IDENTIFIER = McpConnection::SCOPE_NOMINATIVE_READ;

    public function getIdentifier(): string
    {
        return self::IDENTIFIER;
    }
}
