<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use App\Tests\Support\ApiPlatformDeprecations;
use App\Tests\Support\EnvironmentServicesKernel;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Ni le démarrage du noyau, ni la compilation du conteneur, ni la
 * construction des métadonnées des ressources ne déclenchent de dépréciation
 * d'API Platform (issue #701).
 *
 * `ApiPlatformRaisesNoDeprecationTest` n'observe que `$client->request()`. Or
 * plusieurs dépréciations d'API Platform 4.4 naissent ailleurs : à la
 * compilation (`AttributeFilterPass` pour un `#[ApiFilter]`,
 * `ItemNormalizerDecorationBcPass` pour un normaliseur décoré,
 * `ApiPlatformExtension` pour `jsonapi.use_iri_as_id`) et à la construction
 * des métadonnées (`FiltersResourceMetadataCollectionFactory` pour
 * `Operation::$filters`, `MetadataCollectionFactoryTrait` pour deux
 * `#[ApiResource]` de même nom court). Le conteneur et les métadonnées sont mis
 * en cache (`var/cache/test`, pools `api_platform.cache.metadata.*`) : une
 * suite qui les trouve chauds ne les voit plus, et la dépréciation ne paraît
 * que sur la machine de CI propre, ou en production au premier démarrage.
 *
 * Trois observations, chacune la plus ciblée qui atteint son étage :
 *
 * - **le démarrage** : `bootKernel()` sur le conteneur compilé, qui exécute le
 *   `boot()` des bundles ;
 * - **la compilation** : `EnvironmentServicesKernel` compile en mémoire le
 *   `ContainerBuilder` de `test` (extensions, passes de compilation), sans
 *   écrire le conteneur — de 0,3 à 0,7 s, quand écrire et recharger tout le
 *   cache l'alourdirait sans rien voir de plus ;
 * - **les métadonnées à froid** : les pools de métadonnées d'API Platform sont
 *   vidés, puis la collection de métadonnées de **toutes** les ressources est
 *   construite. Vider ces pools ne coûte rien aux tests suivants : la
 *   construction qui suit les remplit de nouveau à l'identique.
 *
 * Limites : la compilation est lue hors débogage, comme les noyaux de parité
 * (`ProcessEnvironment`) ; une dépréciation qui ne naît qu'au premier usage
 * d'un service (un normaliseur à la première normalisation) n'est vue que par
 * les requêtes de `ApiPlatformRaisesNoDeprecationTest`.
 */
final class ApiPlatformStartupRaisesNoDeprecationTest extends KernelTestCase
{
    /** Les pools où API Platform garde les métadonnées qu'il construit. */
    private const array METADATA_POOLS = [
        'api_platform.cache.metadata.resource',
        'api_platform.cache.metadata.resource_collection',
        'api_platform.cache.metadata.property',
    ];

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    public function testBootingTheKernelRaisesNoApiPlatformDeprecation(): void
    {
        $findings = ApiPlatformDeprecations::during(static function (): void {
            self::bootKernel();
        });

        self::assertSame([], $findings, "API Platform déprécie ce que le démarrage du noyau emprunte :\n - ".implode("\n - ", $findings));
    }

    public function testCompilingTheContainerRaisesNoApiPlatformDeprecation(): void
    {
        $findings = ApiPlatformDeprecations::during(static function (): void {
            // Le noyau est nommé d'après son usage premier (les services de
            // sécurité) ; ce qu'il fait ici est de compiler le conteneur de `test`.
            new EnvironmentServicesKernel('test', [])->securityServices();
        });

        self::assertSame([], $findings, "API Platform déprécie ce que la compilation du conteneur emprunte :\n - ".implode("\n - ", $findings));
    }

    public function testBuildingEveryResourceMetadataFromColdRaisesNoApiPlatformDeprecation(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        foreach (self::METADATA_POOLS as $id) {
            $pool = $container->get($id);
            self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
            $pool->clear();
        }
        $names = $container->get(ResourceNameCollectionFactoryInterface::class);
        $metadata = $container->get(ResourceMetadataCollectionFactoryInterface::class);
        $built = 0;

        $findings = ApiPlatformDeprecations::during(static function () use ($names, $metadata, &$built): void {
            foreach ($names->create() as $resourceClass) {
                $metadata->create($resourceClass);
                ++$built;
            }
        });

        self::assertGreaterThan(0, $built, 'Aucune ressource construite : la garde ne verrait rien.');
        self::assertSame([], $findings, "API Platform déprécie ce que la construction des métadonnées emprunte :\n - ".implode("\n - ", $findings));
    }
}
