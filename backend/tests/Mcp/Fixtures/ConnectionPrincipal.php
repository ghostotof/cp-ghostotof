<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Fixtures;

use App\Mcp\Connection\Application\McpConnectionPrincipal;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * L'utilisateur qu'un test unitaire pose dans le `TokenStorage` à la place de
 * celui du firewall `mcp` : une connexion MCP désignée par son seul UUID,
 * sans ligne en base. La vraie classe du firewall
 * ({@see \App\Mcp\Connection\Infrastructure\Security\McpConnectionUser}) ne
 * se construit que depuis une connexion hydratée. Hors de `src/`, jamais
 * chargé par l'application.
 */
final readonly class ConnectionPrincipal implements UserInterface, McpConnectionPrincipal
{
    public function __construct(private Uuid $connectionId)
    {
    }

    public function accountId(): string
    {
        return '00000000-0000-7000-8000-000000000001';
    }

    public function connectionId(): Uuid
    {
        return $this->connectionId;
    }

    public function clientId(): string
    {
        return 'test-client';
    }

    public function getRoles(): array
    {
        return ['ROLE_TRUSTED'];
    }

    public function getUserIdentifier(): string
    {
        return $this->connectionId->toRfc4122();
    }
}
