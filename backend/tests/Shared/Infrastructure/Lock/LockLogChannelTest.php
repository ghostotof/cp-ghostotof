<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Lock;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Le canal `lock` est muet en production (issue #272, audit du correctif,
 * constat I3 ; issue #315).
 *
 * Le composant Lock journalise chaque pose et chaque levée de verrou en
 * `debug`, et chaque échec en `notice`, toujours avec la ressource — la clé
 * du limiteur : `contact_form-<ip>`, `translation_assistant-<username>`,
 * l'identifiant tenté au login. La préprod tourne en `LOG_LEVEL=debug`, et
 * même à `notice` chaque panne du verrou écrivait une IP ou un identifiant
 * hors de `SecurityAuditLogger`, dont la politique de contenu est explicite.
 *
 * #272 avait plafonné le canal à `notice` pour garder les échecs visibles.
 * Depuis #276, `RateLimiterLockFailureListener` écrit pour toute panne sous
 * `/api` une ligne `error` sans la ressource : le handler `lock` passe à
 * `warning`, au-dessus de tout ce que le composant émet, et aucun autre
 * handler de production ne reçoit ce canal.
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

    /**
     * `warning` : le composant n'émet rien au-dessus de `notice`. Le handler
     * reste déclaré pour que le canal ne retombe jamais dans `main` par défaut,
     * et pour qu'un futur message `warning` du composant reste visible.
     */
    public function testTheLockChannelHasItsOwnHandlerAboveEverythingTheComponentEmits(): void
    {
        $handler = $this->productionHandler('lock');

        self::assertSame(['lock'], $handler['channels'] ?? null);
        self::assertSame('warning', $handler['level'] ?? null);
        self::assertSame('php://stderr', $handler['path'] ?? null);
    }

    /**
     * Aucun autre handler de production ne reçoit le canal : ni `main`, ni
     * `console` (qui écrit sur la sortie d'un CronJob), ni un handler ajouté
     * plus tard sans liste de canaux.
     */
    public function testNoOtherProductionHandlerReceivesTheLockChannel(): void
    {
        foreach ($this->productionHandlers() as $name => $handler) {
            if ('lock' === $name) {
                continue;
            }

            $channels = $handler['channels'] ?? [];
            self::assertIsArray($channels);
            $inclusive = array_filter($channels, static fn (mixed $channel): bool => \is_string($channel) && !str_starts_with($channel, '!'));

            self::assertTrue(
                [] !== $inclusive ? !\in_array('lock', $inclusive, true) : \in_array('!lock', $channels, true),
                \sprintf('Le handler de production "%s" reçoit le canal lock.', $name),
            );
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
        $config = Yaml::parseFile(\dirname(__DIR__, 4).'/config/packages/monolog.yaml');
        self::assertIsArray($config);
        $handlers = $config['when@prod']['monolog']['handlers'] ?? null;
        self::assertIsArray($handlers);

        /** @var array<string, array<string, mixed>> $handlers */
        return $handlers;
    }
}
