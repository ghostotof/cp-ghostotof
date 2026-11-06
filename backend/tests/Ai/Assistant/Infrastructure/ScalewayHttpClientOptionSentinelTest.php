<?php

declare(strict_types=1);

namespace App\Tests\Ai\Assistant\Infrastructure;

use App\Tests\Support\TestKeys;
use PHPUnit\Framework\TestCase;
use Symfony\AI\AiBundle\AiBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Sentinelle du contournement de services.yaml (spec 0005 D2, règle ai.md).
 *
 * La plateforme Scaleway de l'assistant est déclarée dans services.yaml, pas
 * dans ai.yaml, parce que symfony/ai-bundle, à la version épinglée
 * (composer.json), ignore l'option `http_client` d'une plateforme `scaleway` :
 * son bloc `'scaleway' === $type` (src/AiBundle.php) passe toujours la
 * référence `http_client` en dur, alors que le nœud de configuration accepte
 * l'option (config/platform/scaleway.php). ScalewayPlatformWiringTest prouve
 * que le contournement marche ; ce test-ci prouve qu'il est encore nécessaire,
 * et rougit le jour où une montée du bundle honore l'option.
 *
 * Ce n'est pas la seule raison du contournement : le même nœud n'a pas non plus
 * d'URL de base, et le bloc laisse celle de Factory::createPlatform() par
 * défaut (`https://api.scaleway.ai`), sans le projet que services.yaml met
 * dans le chemin (sans lui, 403). Le jour où ce test rougit, le rapatriement
 * dans ai.yaml n'est donc possible que si le bundle accepte aussi cette URL ;
 * le message d'échec le dit.
 *
 * Il charge la seule extension du bundle, sur un conteneur vide, avec une
 * plateforme `scaleway` qui demande un client nommé, et lit l'argument
 * « client HTTP » de la définition `ai.platform.scaleway` produite. Rien n'est
 * compilé ni instancié, aucun appel ne sort.
 */
final class ScalewayHttpClientOptionSentinelTest extends TestCase
{
    /** Client demandé par la configuration, qui n'existe nulle part ailleurs. */
    private const string REQUESTED_HTTP_CLIENT = 'app.sentinel.scaleway_http_client';

    /** Ce que le bloc `scaleway` du bundle passe en dur. */
    private const string HARD_CODED_HTTP_CLIENT = 'http_client';

    /** Position de `$httpClient` dans `Factory::createPlatform()` (après `$apiKey`). */
    private const int HTTP_CLIENT_ARGUMENT = 1;

    public function testTheBundleStillIgnoresTheHttpClientOptionOfAScalewayPlatform(): void
    {
        $container = $this->loadAiExtension([
            'platform' => [
                'scaleway' => [
                    'api_key' => TestKeys::material('scaleway-sentinel'),
                    'http_client' => self::REQUESTED_HTTP_CLIENT,
                ],
            ],
        ]);

        self::assertTrue($container->hasDefinition('ai.platform.scaleway'), 'Le bundle ne déclare plus `ai.platform.scaleway` : relire son bloc `scaleway` avant de conclure quoi que ce soit.');
        $httpClient = $container->getDefinition('ai.platform.scaleway')->getArgument(self::HTTP_CLIENT_ARGUMENT);
        self::assertInstanceOf(Reference::class, $httpClient, 'L\'argument « client HTTP » de `ai.platform.scaleway` n\'est plus une référence : la signature de Factory::createPlatform() a changé, relire le bloc `scaleway` du bundle.');

        self::assertNotSame(
            self::REQUESTED_HTTP_CLIENT,
            (string) $httpClient,
            'symfony/ai-bundle honore désormais l\'option `http_client` d\'une plateforme `scaleway` : le contournement de services.yaml est peut-être inutile. '
            .'Vérifier d\'abord que le nœud `ai.platform.scaleway` accepte aussi une URL de base portant le projet (`https://api.scaleway.ai/<projet>`), sans quoi l\'API répond 403. '
            .'Si oui : rapatrier la plateforme dans ai.yaml (`http_client: ai.scaleway.http_client`, la clé, l\'URL de base), supprimer `app.ai.platform.scaleway` de services.yaml, '
            .'faire pointer l\'agent `career_assistant` sur `ai.platform.scaleway`, garder ScalewayPlatformWiringTest vert, retirer ce test, mettre à jour la règle ai.md et le commentaire de services.yaml. '
            .'Si non : garder la déclaration de services.yaml et remplacer cette sentinelle par une sur l\'URL de base.',
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
