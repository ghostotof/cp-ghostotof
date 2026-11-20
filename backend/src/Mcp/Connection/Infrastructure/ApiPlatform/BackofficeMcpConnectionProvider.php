<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Mcp\Connection\Application\AdministeredMcpConnectionsInterface;
use App\Mcp\Connection\Application\AdministeredMcpConnectionView;
use App\Mcp\Connection\Presentation\ApiResource\BackofficeMcpConnectionResource;

/**
 * Lit les connexions MCP de tous les comptes pour la collection du backoffice
 * (#652). Une seule opération : pas de lecture d'élément. La lecture, les
 * bornes et l'instantané sont ceux d'{@see AdministeredMcpConnectionsInterface}.
 *
 * @implements ProviderInterface<BackofficeMcpConnectionResource>
 */
final readonly class BackofficeMcpConnectionProvider implements ProviderInterface
{
    public function __construct(private AdministeredMcpConnectionsInterface $connections)
    {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @return list<BackofficeMcpConnectionResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return array_map(
            static fn (AdministeredMcpConnectionView $view): BackofficeMcpConnectionResource => BackofficeMcpConnectionResource::fromView($view),
            $this->connections->list(),
        );
    }
}
