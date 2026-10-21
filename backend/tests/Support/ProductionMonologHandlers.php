<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;
use Symfony\Component\Yaml\Yaml;

/**
 * Les handlers Monolog de `when@prod`, lus dans monolog.yaml.
 *
 * `when@prod` couvre la préprod et la prod (même `APP_ENV`) ; le noyau de test
 * ne charge pas cette section, d'où la lecture du fichier. Partagé par les tests
 * qui figent le traitement d'un canal en production (`ai_usage`, `lock`, `mcp`,
 * `mcp_usage`).
 */
final class ProductionMonologHandlers
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $config = Yaml::parseFile(RepositoryFiles::backend('config/packages/monolog.yaml'));
        Assert::assertIsArray($config);
        $handlers = $config['when@prod']['monolog']['handlers'] ?? null;
        Assert::assertIsArray($handlers);

        /** @var array<string, array<string, mixed>> $handlers */
        return $handlers;
    }

    /**
     * @return array<string, mixed>
     */
    public static function named(string $name): array
    {
        $handler = self::all()[$name] ?? null;
        Assert::assertIsArray($handler, \sprintf('Handler "%s" absent de when@prod.', $name));

        /** @var array<string, mixed> $handler */
        return $handler;
    }

    /**
     * Un handler reçoit un canal s'il le liste, ou s'il n'en liste aucun en
     * inclusion et ne l'exclut pas — un handler sans liste reçoit tout.
     *
     * @param array<string, mixed> $handler
     */
    public static function receives(array $handler, string $channel): bool
    {
        $channels = $handler['channels'] ?? [];
        Assert::assertIsArray($channels);
        $inclusive = array_filter($channels, static fn (mixed $listed): bool => \is_string($listed) && !str_starts_with($listed, '!'));

        return [] !== $inclusive ? \in_array($channel, $inclusive, true) : !\in_array('!'.$channel, $channels, true);
    }
}
