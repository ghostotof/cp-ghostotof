<?php

declare(strict_types=1);

namespace App\Tests\Ai\Assistant\Infrastructure;

use PHPUnit\Framework\Assert;
use Symfony\AI\AiBundle\AiBundle;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;

/**
 * Le nœud de configuration `ai.platform.scaleway` de symfony/ai-bundle, lu
 * dans l'arbre que construit son
 * {@see \Symfony\Component\Config\Definition\Builder\TreeBuilder} : rien n'est
 * chargé ni compilé, aucun appel ne sort.
 *
 * Partagé par les deux sentinelles du contournement de services.yaml
 * ({@see \App\Tests\Ai\Assistant\Infrastructure\ScalewayHttpClientOptionSentinelTest},
 * {@see \App\Tests\Ai\Assistant\Infrastructure\ScalewayPlatformOptionListSentinelTest}) : la seconde juge la liste
 * des options du nœud, la première vérifie que celles qu'elle configure y
 * sont encore avant de charger l'extension.
 */
trait ReadsTheScalewayPlatformNode
{
    private static function scalewayNode(): ArrayNode
    {
        $extension = (new AiBundle())->getContainerExtension();
        Assert::assertInstanceOf(ConfigurationExtensionInterface::class, $extension);
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        Assert::assertNotNull($configuration);

        $root = $configuration->getConfigTreeBuilder()->buildTree();
        Assert::assertInstanceOf(ArrayNode::class, $root);
        $platforms = $root->getChildren()['platform'] ?? null;
        Assert::assertInstanceOf(ArrayNode::class, $platforms, 'Le bundle n\'a plus de nœud `ai.platform` : relire sa configuration.');
        $scaleway = $platforms->getChildren()['scaleway'] ?? null;
        Assert::assertInstanceOf(ArrayNode::class, $scaleway, 'Le bundle n\'a plus de nœud `ai.platform.scaleway` : relire sa configuration.');

        return $scaleway;
    }

    /**
     * Les noms des options du nœud, dans l'ordre où le bundle les déclare.
     *
     * @return list<string>
     */
    private static function scalewayNodeOptions(): array
    {
        return array_map(strval(...), array_keys(self::scalewayNode()->getChildren()));
    }
}
