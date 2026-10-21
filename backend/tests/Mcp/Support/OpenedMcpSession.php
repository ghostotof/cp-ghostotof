<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Support;

/**
 * Une session MCP ouverte par {@see SpeaksMcp::openMcpSession()} : son
 * identifiant, et le `result` de la réponse à `initialize` — `serverInfo`,
 * `instructions`, capacités : ce que le serveur dit de lui-même.
 */
final readonly class OpenedMcpSession
{
    /**
     * @param array<string, mixed> $initializeResult
     */
    public function __construct(
        public string $id,
        public array $initializeResult,
    ) {
    }
}
