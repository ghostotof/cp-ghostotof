<?php

declare(strict_types=1);

namespace App\Tests\Ai\Assistant\Infrastructure;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Le coût de l'assistant (jetons, durée, ADR 0004 D5) doit se relire en
 * production. Le handler `main` y suit LOG_LEVEL, `warning` par défaut : un
 * `info` du canal applicatif n'en sortirait jamais. Le canal `ai_usage` a donc,
 * comme `security_audit`, son propre handler à niveau fixe, et `main` l'exclut
 * pour qu'un enregistrement ne sorte qu'une fois.
 *
 * `when@prod` couvre la production et la préprod (même APP_ENV) ; il n'est
 * chargé par aucun test fonctionnel, d'où la lecture du fichier.
 */
final class AiUsageLogChannelTest extends TestCase
{
    private const string MONOLOG_CONFIG = __DIR__.'/../../../../config/packages/monolog.yaml';

    public function testTheChannelHasItsOwnInfoLevelHandlerInProduction(): void
    {
        $handlers = $this->productionHandlers();

        $dedicated = array_filter(
            $handlers,
            static fn (array $handler): bool => \in_array('ai_usage', $handler['channels'] ?? [], true),
        );

        self::assertCount(1, $dedicated, 'Un et un seul handler doit porter le canal ai_usage en production.');
        self::assertSame('info', array_values($dedicated)[0]['level'] ?? null);
    }

    public function testTheMainHandlerExcludesIt(): void
    {
        self::assertContains('!ai_usage', $this->productionHandlers()['main']['channels'] ?? []);
    }

    /**
     * @return array<string, array{channels?: list<string>, level?: string}>
     */
    private function productionHandlers(): array
    {
        /** @var array{'when@prod': array{monolog: array{handlers: array<string, array{channels?: list<string>, level?: string}>}}} $config */
        $config = Yaml::parseFile(self::MONOLOG_CONFIG);

        return $config['when@prod']['monolog']['handlers'];
    }
}
