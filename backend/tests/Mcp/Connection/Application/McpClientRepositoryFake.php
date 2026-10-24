<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use Throwable;

/**
 * Le dépôt factice de McpClientRegistrarTest : `existing` répond à la
 * lecture, `failure` est levée par l'ajout, `added` garde ce qui a été ajouté.
 * Une classe nommée plutôt qu'anonyme, pour que PHPStan connaisse `added`.
 */
final class McpClientRepositoryFake implements McpClientRepositoryInterface
{
    /** @var list<McpClient> */
    public array $added = [];

    public function __construct(
        private readonly ?McpClient $existing,
        private readonly ?Throwable $failure,
    ) {
    }

    public function findOneByClientId(string $clientId): ?McpClient
    {
        return $this->existing;
    }

    public function add(McpClient $client): void
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }

        $this->added[] = $client;
    }
}
