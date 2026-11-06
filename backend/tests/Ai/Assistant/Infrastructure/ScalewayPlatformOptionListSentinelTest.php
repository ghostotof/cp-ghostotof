<?php

declare(strict_types=1);

namespace App\Tests\Ai\Assistant\Infrastructure;

use PHPUnit\Framework\TestCase;
use Symfony\AI\AiBundle\AiBundle;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;

/**
 * Seconde sentinelle du contournement de services.yaml (spec 0005 D2, ADR 0004
 * D1 amendée le 2026-11-06) : la liste fermée des options du nœud
 * `ai.platform.scaleway`.
 *
 * La plateforme Scaleway de l'assistant est déclarée dans services.yaml pour
 * deux raisons. La première, l'option `http_client` ignorée, a sa sentinelle
 * ({@see \App\Tests\Ai\Assistant\Infrastructure\ScalewayHttpClientOptionSentinelTest}).
 * Celle-ci garde la seconde : le nœud de configuration `ai.platform.scaleway`
 * de symfony/ai-bundle, à la version épinglée (composer.json), n'offre aucune
 * URL de base. Le bundle laisse donc celle de
 * {@see \Symfony\AI\Platform\Bridge\Scaleway\Factory::createPlatform()}
 * par défaut, `https://api.scaleway.ai`, sans le projet que services.yaml met
 * dans le chemin ; l'API vise alors le projet par défaut de l'organisation et
 * la clé, limitée à un autre projet, reçoit un 403.
 *
 * Le test exige que les options du nœud soient exactement celles qu'on lui
 * connaît : il rougit sur **toute** option nouvelle, quel que soit son nom.
 * Deviner le nom que prendrait une URL de base (un motif sur `url`, `host`,
 * `endpoint`…) laisserait passer celui qu'on n'a pas prévu. Le jour où il
 * rougit, la marche à suivre est dans la règle `.claude/rules/ai.md`,
 * « Moving the Scaleway platform back into `ai.yaml` » : une option qui ne
 * porte pas le projet entre dans la liste, une qui le porte fait tomber la
 * raison.
 *
 * Il lit l'arbre de configuration du bundle, construit par son
 * {@see \Symfony\Component\Config\Definition\Builder\TreeBuilder} : rien n'est
 * chargé ni compilé, aucun appel ne sort.
 */
final class ScalewayPlatformOptionListSentinelTest extends TestCase
{
    /**
     * Les options du nœud à la version épinglée, triées : la clé, et le client
     * HTTP (que le bundle ignore, voir l'autre sentinelle). Aucune ne porte
     * d'URL de base.
     */
    private const array KNOWN_OPTIONS = ['api_key', 'http_client'];

    public function testTheScalewayNodeOffersExactlyTheKnownOptions(): void
    {
        // L'ordre de déclaration dans le bundle ne dit rien : seul l'ensemble compte.
        $options = array_keys($this->scalewayNode()->getChildren());
        sort($options);

        self::assertSame(
            self::KNOWN_OPTIONS,
            $options,
            'Les options du nœud `ai.platform.scaleway` ont changé : suivre la marche à suivre de .claude/rules/ai.md, « Moving the Scaleway platform back into `ai.yaml` ».',
        );
    }

    private function scalewayNode(): ArrayNode
    {
        $extension = (new AiBundle())->getContainerExtension();
        self::assertInstanceOf(ConfigurationExtensionInterface::class, $extension);
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        self::assertNotNull($configuration);

        $root = $configuration->getConfigTreeBuilder()->buildTree();
        self::assertInstanceOf(ArrayNode::class, $root);
        $platforms = $root->getChildren()['platform'] ?? null;
        self::assertInstanceOf(ArrayNode::class, $platforms, 'Le bundle n\'a plus de nœud `ai.platform` : relire sa configuration.');
        $scaleway = $platforms->getChildren()['scaleway'] ?? null;
        self::assertInstanceOf(ArrayNode::class, $scaleway, 'Le bundle n\'a plus de nœud `ai.platform.scaleway` : relire sa configuration.');

        return $scaleway;
    }
}
