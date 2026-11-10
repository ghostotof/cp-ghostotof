<?php

declare(strict_types=1);

namespace App\Tests\Ai\Assistant\Infrastructure;

use App\Tests\Support\TestKeys;
use PHPUnit\Framework\TestCase;
use Symfony\AI\AiBundle\AiBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Première sentinelle du contournement de services.yaml (spec 0005 D2, ADR
 * 0004 D1 amendée le 2026-11-06).
 *
 * La plateforme Scaleway de l'assistant est déclarée dans services.yaml, pas
 * dans ai.yaml, pour deux raisons. Celle que garde ce test : symfony/ai-bundle,
 * à la version épinglée (composer.json), ignore l'option `http_client` d'une
 * plateforme `scaleway` ; son bloc `'scaleway' === $type` (src/AiBundle.php)
 * passe toujours la référence `http_client` en dur, alors que le nœud de
 * configuration accepte l'option (config/platform/scaleway.php). La seconde, le
 * nœud sans URL de base où porter le projet (sans lui, 403), a sa propre
 * sentinelle, la liste fermée des options du nœud
 * ({@see \App\Tests\Ai\Assistant\Infrastructure\ScalewayPlatformOptionListSentinelTest}).
 *
 * {@see \App\Tests\Ai\Assistant\Infrastructure\ScalewayPlatformWiringTest}
 * prouve que le contournement marche ; ce test-ci prouve qu'il est encore
 * nécessaire, et rougit le jour où une montée du bundle honore l'option. La
 * marche à suivre, alors, est dans la règle `.claude/rules/ai.md`,
 * « Moving the Scaleway platform back into `ai.yaml` ».
 *
 * Il charge la seule extension du bundle, sur un conteneur vide, avec une
 * plateforme `scaleway` qui demande un client nommé, et lit l'argument
 * « client HTTP » de la définition `ai.platform.scaleway` produite. Rien n'est
 * compilé ni instancié, aucun appel ne sort.
 */
final class ScalewayHttpClientOptionSentinelTest extends TestCase
{
    use ReadsTheScalewayPlatformNode;

    /** Client demandé par la configuration, qui n'existe nulle part ailleurs. */
    private const string REQUESTED_HTTP_CLIENT = 'app.sentinel.scaleway_http_client';

    /** Ce que le bloc `scaleway` du bundle passe en dur. */
    private const string HARD_CODED_HTTP_CLIENT = 'http_client';

    /**
     * Position de `$httpClient` dans
     * {@see \Symfony\AI\Platform\Bridge\Scaleway\Factory::createPlatform()}
     * (après `$apiKey`).
     */
    private const int HTTP_CLIENT_ARGUMENT = 1;

    public function testTheBundleStillIgnoresTheHttpClientOptionOfAScalewayPlatform(): void
    {
        $scaleway = [
            'api_key' => TestKeys::material('scaleway-sentinel'),
            'http_client' => self::REQUESTED_HTTP_CLIENT,
        ];

        // Une option retirée du nœud ferait échouer le chargement en erreur de
        // configuration, sans dire laquelle des deux raisons est en jeu : on le
        // dit d'abord, lisiblement, pour chaque option que la configuration
        // ci-dessus passe (la liste vérifiée en dérive, elle ne se recopie pas).
        $nodeOptions = self::scalewayNodeOptions();
        foreach (array_keys($scaleway) as $option) {
            self::assertContains(
                $option,
                $nodeOptions,
                \sprintf('Le nœud `ai.platform.scaleway` n\'accepte plus l\'option `%s`, que cette sentinelle configure : suivre .claude/rules/ai.md, « Moving the Scaleway platform back into `ai.yaml` », cas d\'une option retirée.', $option),
            );
        }

        $container = $this->loadAiExtension(['platform' => ['scaleway' => $scaleway]]);

        self::assertTrue($container->hasDefinition('ai.platform.scaleway'), 'Le bundle ne déclare plus `ai.platform.scaleway` : relire son bloc `scaleway`.');
        $httpClient = $container->getDefinition('ai.platform.scaleway')->getArgument(self::HTTP_CLIENT_ARGUMENT);
        self::assertInstanceOf(Reference::class, $httpClient, 'Le client HTTP de `ai.platform.scaleway` n\'est plus une référence : la signature de la Factory a changé.');

        self::assertNotSame(
            self::REQUESTED_HTTP_CLIENT,
            (string) $httpClient,
            'symfony/ai-bundle honore désormais l\'option `http_client` d\'une plateforme `scaleway` : suivre la marche à suivre de .claude/rules/ai.md, « Moving the Scaleway platform back into `ai.yaml` ».',
        );
        self::assertSame(self::HARD_CODED_HTTP_CLIENT, (string) $httpClient, 'Le bloc `scaleway` du bundle passe un autre client que `http_client` sans honorer l\'option : relire ce bloc.');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function loadAiExtension(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $container->setParameter('kernel.cache_dir', sys_get_temp_dir());

        $extension = (new AiBundle())->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load([$config], $container);

        return $container;
    }
}
