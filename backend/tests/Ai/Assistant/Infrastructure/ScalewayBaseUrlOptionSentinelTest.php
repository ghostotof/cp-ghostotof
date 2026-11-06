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
 * D1 amendée le 2026-11-06, règle ai.md).
 *
 * La plateforme Scaleway de l'assistant est déclarée dans services.yaml pour
 * deux raisons. La première, l'option `http_client` ignorée, a sa sentinelle
 * ({@see ScalewayHttpClientOptionSentinelTest}). Celle-ci garde la seconde : le
 * nœud de configuration `ai.platform.scaleway` de symfony/ai-bundle, à la
 * version épinglée (composer.json), n'offre aucune option d'URL de base. Le
 * bundle laisse donc celle de
 * {@see \Symfony\AI\Platform\Bridge\Scaleway\Factory::createPlatform()} par
 * défaut, `https://api.scaleway.ai`, sans le projet que services.yaml met dans
 * le chemin ; l'API vise alors le projet par défaut de l'organisation et la
 * clé, limitée à un autre projet, reçoit un 403.
 *
 * Le test lit l'arbre de configuration du bundle (son TreeBuilder, rien n'est
 * chargé ni compilé) et rougit le jour où le nœud `scaleway` gagne une option
 * qui pourrait porter le projet.
 */
final class ScalewayBaseUrlOptionSentinelTest extends TestCase
{
    /** Noms d'option qui diraient « où envoyer » : URL, hôte, point d'accès, projet, région. */
    private const string LOCATION_OPTION_PATTERN = '/url|uri|endpoint|host|project|region/i';

    public function testTheScalewayNodeStillOffersNoBaseUrl(): void
    {
        $addressOptions = preg_grep(self::LOCATION_OPTION_PATTERN, array_keys($this->scalewayNode()->getChildren()));
        self::assertIsArray($addressOptions);

        self::assertSame(
            [],
            array_values($addressOptions),
            'Le nœud `ai.platform.scaleway` accepte désormais une option d\'adresse : vérifier qu\'elle porte le projet (`https://api.scaleway.ai/<projet>`). '
            .'Si oui, voir ScalewayHttpClientOptionSentinelTest : verte, garder services.yaml et retirer ce test ; '
            .'rouge aussi, rapatrier la plateforme dans ai.yaml (marche à suivre dans son docblock).',
        );
    }

    /** Le motif reconnaît les noms que le bundle donne déjà à cette option sur d'autres plateformes. */
    public function testThePatternRecognisesTheAddressOptionsOfOtherPlatforms(): void
    {
        foreach (['base_url', 'endpoint', 'host', 'host_url', 'project_id'] as $option) {
            self::assertMatchesRegularExpression(self::LOCATION_OPTION_PATTERN, $option);
        }

        self::assertDoesNotMatchRegularExpression(self::LOCATION_OPTION_PATTERN, 'api_key');
        self::assertDoesNotMatchRegularExpression(self::LOCATION_OPTION_PATTERN, 'http_client');
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
