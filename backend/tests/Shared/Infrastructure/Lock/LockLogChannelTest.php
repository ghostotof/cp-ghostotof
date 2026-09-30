<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Lock;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Le canal `lock` ne suit pas le niveau du handler `main` en production
 * (issue #272, audit du correctif, constat I3).
 *
 * Le composant Lock journalise chaque pose et chaque levée de verrou en
 * `debug`, avec la ressource — `contact_form-<ip>`,
 * `translation_assistant-<username>`. Or la préprod tourne en
 * `LOG_LEVEL=debug` : chaque requête limitée y écrirait une adresse IP ou un
 * identifiant hors de `SecurityAuditLogger`, dont la politique de contenu est
 * explicite. Le canal a donc son propre handler, plafonné à `notice` : les
 * échecs d'acquisition ou de libération restent visibles, le trafic nominal non.
 *
 * `when@prod` couvre la préprod et la prod (même `APP_ENV`) ; le noyau de test
 * ne charge pas cette section, d'où la lecture du fichier.
 */
final class LockLogChannelTest extends TestCase
{
    public function testTheMainProductionHandlerLeavesTheLockChannelOut(): void
    {
        self::assertContains('!lock', $this->productionHandler('main')['channels'] ?? []);
    }

    public function testTheLockChannelHasItsOwnHandlerCappedAtNotice(): void
    {
        $handler = $this->productionHandler('lock');

        self::assertSame(['lock'], $handler['channels'] ?? null);
        self::assertSame('notice', $handler['level'] ?? null);
        self::assertSame('php://stderr', $handler['path'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function productionHandler(string $name): array
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 4).'/config/packages/monolog.yaml');
        self::assertIsArray($config);
        $handler = $config['when@prod']['monolog']['handlers'][$name] ?? null;
        self::assertIsArray($handler, \sprintf('Handler "%s" absent de when@prod.', $name));

        /** @var array<string, mixed> $handler */
        return $handler;
    }
}
