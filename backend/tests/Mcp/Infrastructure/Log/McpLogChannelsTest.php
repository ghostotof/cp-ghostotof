<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Infrastructure\Log;

use App\Tests\Support\RepositoryFiles;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Les deux canaux du serveur MCP en production (spec 0007, tâche 4, #643).
 *
 *  - `mcp` est celui du SDK et du bundle, étiqueté en dur par McpBundle. Il
 *    écrit en `info`/`debug` le message JSON-RPC brut, arguments compris (le
 *    `role` du prompt est du texte libre), et les identifiants de session ; la
 *    préprod tourne en `LOG_LEVEL=debug`. Son handler est donc plafonné à
 *    `warning` dans **tous** les environnements déployés, comme `lock`, et
 *    aucun autre handler ne le reçoit.
 *  - `mcp_usage` est le journal d'appels : une ligne `info` par requête, qui
 *    doit exister en production où `main` écarte un `info` — son propre
 *    handler à niveau fixe, comme `ai_usage`.
 *
 * `when@prod` couvre la préprod et la prod (même `APP_ENV`) ; le noyau de test
 * ne charge pas cette section, d'où la lecture du fichier.
 */
final class McpLogChannelsTest extends TestCase
{
    public function testTheSdkChannelHasItsOwnHandlerCappedAtWarning(): void
    {
        $handler = $this->productionHandler('mcp');

        self::assertSame(['mcp'], $handler['channels'] ?? null);
        self::assertSame('warning', $handler['level'] ?? null);
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
        foreach (['mcp'] as $channel) {
            foreach ($this->productionHandlers() as $name => $handler) {
                if ($channel === $name) {
                    continue;
                }

                $channels = $handler['channels'] ?? [];
                self::assertIsArray($channels);
                $inclusive = array_filter($channels, static fn (mixed $listed): bool => \is_string($listed) && !str_starts_with($listed, '!'));

                self::assertTrue(
                    [] !== $inclusive ? !\in_array($channel, $inclusive, true) : \in_array('!'.$channel, $channels, true),
                    \sprintf('Le handler de production "%s" reçoit le canal %s.', $name, $channel),
                );
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function productionHandler(string $name): array
    {
        $handler = $this->productionHandlers()[$name] ?? null;
        self::assertIsArray($handler, \sprintf('Handler "%s" absent de when@prod.', $name));

        return $handler;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function productionHandlers(): array
    {
        $config = Yaml::parseFile(RepositoryFiles::backend('config/packages/monolog.yaml'));
        self::assertIsArray($config);
        $handlers = $config['when@prod']['monolog']['handlers'] ?? null;
        self::assertIsArray($handlers);

        /** @var array<string, array<string, mixed>> $handlers */
        return $handlers;
    }
}
