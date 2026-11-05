<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation\Factory\OperationMetadataFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use App\Tests\Support\ApiPlatformDeprecations;
use App\Tests\Support\DeprecatedReferencesOfApiPlatform;
use App\Tests\Support\EnvironmentServicesKernel;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Ni le démarrage du noyau, ni la compilation du conteneur, ni la
 * construction des métadonnées des ressources ne déclenchent de dépréciation
 * d'API Platform (issue #701).
 *
 * {@see \App\Tests\Shared\Infrastructure\ApiPlatform\ApiPlatformRaisesNoDeprecationTest}
 * n'observe que `$client->request()`. Or
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
 *   cache l'alourdirait sans rien voir de plus. Une passe de lecture
 *   (`DeprecatedReferencesOfApiPlatform`) relève en plus les services
 *   d'API Platform qui référencent un alias déprécié d'un autre paquet : cette
 *   dépréciation-là est levée par une passe de Symfony, sans trame d'API
 *   Platform ;
 * - **les métadonnées à froid** : les pools de métadonnées d'API Platform sont
 *   vidés, puis la collection de métadonnées de **toutes** les ressources est
 *   construite, puis **chaque opération** est résolue par son nom
 *   (`OperationMetadataFactoryInterface::create()`, le chemin que le lecteur
 *   de routes du serveur MCP emprunte, et dont le pool est le quatrième de la
 *   liste). Vider ces pools ne coûte rien aux tests suivants : la
 *   construction qui suit les remplit de nouveau à l'identique.
 *   Un appel par opération nommée, et toutes doivent être résolues : un
 *   `create()` qui rend `null` n'a rien fait construire, et le compter
 *   rendrait la garde aveugle sans qu'elle rougisse. La factory n'a pas
 *   d'index : à chaque nom qu'elle n'a pas encore vu elle reparcourt les
 *   ressources, d'où un coût quadratique qu'on ne peut pas éviter d'ici ;
 *   mesuré en 4.4.6 (2026-10-28), 39 ressources et 112 opérations coûtent
 *   1,1 s à froid pour les métadonnées et 0,1 s pour les 112 appels.
 *
 * Limites : la compilation est lue hors débogage, comme les noyaux de parité
 * (`ProcessEnvironment`) ; une dépréciation qui ne naît qu'au premier usage
 * d'un service (un normaliseur à la première normalisation) n'est vue que par
 * les requêtes de ce test-là.
 */
final class ApiPlatformStartupRaisesNoDeprecationTest extends KernelTestCase
{
    /** Les pools où API Platform garde les métadonnées qu'il construit. */
    private const array METADATA_POOLS = [
        'api_platform.cache.metadata.resource',
        'api_platform.cache.metadata.resource_collection',
        'api_platform.cache.metadata.property',
        'api_platform.cache.metadata.operation',
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
        $references = new DeprecatedReferencesOfApiPlatform();

        $findings = ApiPlatformDeprecations::during(static function () use ($references): void {
            // Le noyau est nommé d'après son usage premier (les services de
            // sécurité) ; ce qu'il fait ici est de compiler le conteneur de `test`.
            new EnvironmentServicesKernel('test', [], [$references])->securityServices();
        });

        self::assertSame([], $findings, "API Platform déprécie ce que la compilation du conteneur emprunte :\n - ".implode("\n - ", $findings));
        // Ce que les passes de Symfony lèvent sans trame d'API Platform : un
        // service d'API Platform qui référence un alias déprécié.
        self::assertSame([], $references->found(), "Un service d'API Platform référence une API dépréciée :\n - ".implode("\n - ", $references->found()));
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
        $operations = $container->get(OperationMetadataFactoryInterface::class);
        $built = 0;
        $resolved = 0;
        $unresolved = [];

        $findings = ApiPlatformDeprecations::during(static function () use ($names, $metadata, $operations, &$built, &$resolved, &$unresolved): void {
            foreach ($names->create() as $resourceClass) {
                foreach ($metadata->create($resourceClass) as $resource) {
                    ++$built;
                    foreach ($resource->getOperations() ?? [] as $operationName => $operation) {
                        // `create()` rend `null` pour un nom qu'aucune opération ne porte :
                        // une opération non résolue n'a rien fait construire.
                        if (null === $operations->create($operationName)) {
                            $unresolved[] = $operationName;

                            continue;
                        }
                        ++$resolved;
                    }
                }
            }
        });

        self::assertGreaterThan(0, $built, 'Aucune ressource construite : la garde ne verrait rien.');
        self::assertGreaterThan(0, $resolved, 'Aucune opération résolue : la garde ne verrait rien de leur factory.');
        self::assertSame([], $unresolved, "Des opérations nommées par leur ressource ne sont pas résolues par OperationMetadataFactoryInterface::create() :\n - ".implode("\n - ", $unresolved));
        self::assertSame([], $findings, "API Platform déprécie ce que la construction des métadonnées emprunte :\n - ".implode("\n - ", $findings));
    }
}
