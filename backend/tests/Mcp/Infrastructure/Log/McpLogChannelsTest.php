<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Infrastructure\Log;

use App\Tests\Support\ProductionMonologHandlers;
use PHPUnit\Framework\TestCase;

/**
 * Les deux canaux du serveur MCP en production (spec 0007, tâche 4, #643).
 *
 *  - `mcp` est celui du SDK et du bundle, étiqueté en dur par McpBundle. Il
 *    écrit en `info`/`debug` le message JSON-RPC brut, arguments compris (le
 *    `role` du prompt est du texte libre), et les identifiants de session ; la
 *    préprod tourne en `LOG_LEVEL=debug`. Son handler est donc plafonné à
 *    `warning` dans **tous** les environnements déployés, comme `lock`, et
 *    aucun autre handler ne le reçoit.
 *  - `mcp_usage` est le journal d'appels : une ligne `info` par message JSON-RPC, qui
 *    doit exister en production où `main` écarte un `info` — son propre
 *    handler à niveau fixe, comme `ai_usage`.
 */
final class McpLogChannelsTest extends TestCase
{
    public function testTheSdkChannelHasItsOwnHandlerCappedAtWarning(): void
    {
        $handler = ProductionMonologHandlers::named('mcp');

        self::assertSame(['mcp'], $handler['channels'] ?? null);
        self::assertSame('warning', $handler['level'] ?? null);
        self::assertSame('php://stderr', $handler['path'] ?? null);
        self::assertSame('monolog.formatter.json', $handler['formatter'] ?? null);
    }

    public function testTheCallLogHasItsOwnInfoLevelHandler(): void
    {
        $handler = ProductionMonologHandlers::named('mcp_usage');

        self::assertSame(['mcp_usage'], $handler['channels'] ?? null);
        self::assertSame('info', $handler['level'] ?? null);
        self::assertSame('php://stderr', $handler['path'] ?? null);
        self::assertSame('monolog.formatter.json', $handler['formatter'] ?? null);
    }

    /**
     * Ni `main` (qui suit LOG_LEVEL, `debug` en préprod), ni `console`, ni un
     * handler ajouté plus tard sans liste de canaux ne reçoit l'un des deux :
     * un enregistrement sort une fois, au niveau de son propre handler.
     */
    public function testNoOtherProductionHandlerReceivesEitherChannel(): void
    {
        foreach (['mcp', 'mcp_usage'] as $channel) {
            foreach (ProductionMonologHandlers::all() as $name => $handler) {
                if ($channel !== $name) {
                    self::assertFalse(ProductionMonologHandlers::receives($handler, $channel), \sprintf('Le handler de production "%s" reçoit le canal %s.', $name, $channel));
                }
            }
        }
    }
}
