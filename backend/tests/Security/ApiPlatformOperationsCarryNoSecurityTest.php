<?php

declare(strict_types=1);

namespace App\Tests\Security;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Metadata;
use ApiPlatform\Metadata\Parameter;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use ApiPlatform\Metadata\Property\Factory\PropertyNameCollectionFactoryInterface;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\Type\CollectionType;
use Symfony\Component\TypeInfo\Type\CompositeTypeInterface;
use Symfony\Component\TypeInfo\Type\EnumType;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\TypeInfo\Type\WrappingTypeInterface;

/**
 * Aucune opération d'API Platform ne porte d'expression de sécurité, quelle que soit sa
 * source (#748, R61, CR6-7 pour la configuration).
 *
 * Toute autorisation de `/api` est une règle d'`access_control` :
 * {@see \App\Security\Authentication\Infrastructure\Security\PublicRouteIgnoresRefusedSessionListener}
 * tient « route publique = route sans règle » et sert comme l'anonyme une requête dont le
 * cookie `BEARER` est refusé. Un `security:` d'API Platform posé sur une route sans règle
 * romprait cette prémisse en silence (voir {@see \App\Tests\Security\AuthorizationLivesInAccessControlTest}, dont
 * celle-ci est le pendant). Les témoins de cette prémisse sont listés une fois, dans
 * `.claude/rules/security-authentication-sessions.md` (puce de
 * `PublicRouteIgnoresRefusedSessionListener`).
 *
 * **Ce que cette garde voit de plus que celle des jetons** : elle lit les **métadonnées
 * compilées** (chaque ressource et chaque opération que `ResourceMetadataCollectionFactoryInterface`
 * construit, comme `ApiPlatformStartupRaisesNoDeprecationTest` les parcourt), donc l'attribut
 * PHP, mais aussi un `security:` déclaré en YAML ou en XML, ou posé par tout ce qu'API
 * Platform fusionne (un décorateur de factory, les valeurs par défaut globales de
 * `api_platform.defaults`). Lire `config/**` par motifs ne verrait que la forme écrite, et
 * `security:` est aussi la clé racine de `security.yaml`.
 *
 * **Ce qu'elle ne voit pas** : une autorisation hors d'API Platform (`#[IsGranted]` d'un
 * contrôleur, `isGranted()`, un refus levé à la main) — c'est l'autre garde, par jetons.
 * Les opérations GraphQL ne sont pas parcourues : `testGraphQlStaysDisabled` (voir
 * `.claude/rules/backoffice-api.md`) tient GraphQL éteint.
 *
 * Les trois expressions d'API Platform sont refusées sur la ressource et sur chaque opération :
 * `security`, `securityPostDenormalize` et `securityPostValidation` (leurs `…Message` ne servent
 * qu'à les accompagner). Elle lit aussi, pour la ressource et pour chaque opération, les
 * **paramètres** (`getParameters()`) et les **liens** (`getUriVariables()`, des `Link` qui étendent
 * `Parameter` : leur `security`), puis chaque **propriété** de chaque ressource (`ApiProperty` :
 * `security` et `securityPostDenormalize`). « Chaque ressource » veut dire : les classes de ressource, les DTO
 * `input` et `output` de chaque ressource et opération (#748, R88 : `BackofficeUserInviteInput` en est un) et les
 * objets `App\` que le type natif d'une propriété atteint (objet, collection, nullable, union), chaque classe lue
 * une fois. Un lien (`uriVariables`) qui n'est pas un `Link` compilé est un constat, non une omission silencieuse.
 * `getLinks()` n'est pas parcouru : ce sont des `WebLink`
 * de Symfony (l'en-tête HTTP `Link`), qui n'ont pas de `security`.
 *
 * **Pas de garde de vacuité sur les paramètres** : le parcours de `getParameters()` est la
 * même boucle que celle des liens, exécutée sur chaque ressource et chaque opération (comptées) ;
 * `testTheCheckSeesEachParameterExpression` prouve la lecture. Exiger qu'un paramètre existe rougirait pour rien (aucun
 * n'existe dans `src/`), et une ressource de test ajoutée pour cela ajouterait une route `/api` au conteneur `test` que
 * lisent toutes les gardes d'exposition (`ApiRouteExposureTest`, `RouterScopeTest`). Les classes DTO (`src/` en
 * déclare une) et les objets imbriqués (`src/` en atteint neuf à R88) sont, eux, comptés.
 */
final class ApiPlatformOperationsCarryNoSecurityTest extends KernelTestCase
{
    public function testNoOperationCarriesASecurityExpression(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $names = $container->get(ResourceNameCollectionFactoryInterface::class);
        $metadata = $container->get(ResourceMetadataCollectionFactoryInterface::class);
        $propertyNames = $container->get(PropertyNameCollectionFactoryInterface::class);
        $propertyMetadata = $container->get(PropertyMetadataFactoryInterface::class);

        $visited = 0;
        $links = 0;
        $properties = 0;
        $found = [];
        // Les classes dont on lit les propriétés : la file part des ressources, puis y entrent les DTO d'entrée
        // et de sortie de chaque ressource et opération, puis les objets `App\` que le type d'une propriété atteint.
        // Chaque classe est lue une fois (la clé de `$queue` est la classe).
        $queue = [];
        $dtoClasses = [];
        $nestedClasses = [];
        foreach ($names->create() as $resourceClass) {
            $queue[$resourceClass] = true;
            foreach ($metadata->create($resourceClass) as $resource) {
                // La ressource elle-même : API Platform recopie ses valeurs sur les opérations
                // qui n'ont pas les leurs, mais un défaut posé ici se verrait au plus tôt.
                foreach ($this->expressionsOf($resource) as $attribute => $expression) {
                    $found[] = \sprintf('%s (ressource) : %s = %s', $resourceClass, $attribute, $expression);
                }
                $links += $this->collectNested($resource, \sprintf('%s (ressource)', $resourceClass), $found);
                foreach ($this->dtoClassesOf($resource) as $dtoClass) {
                    $queue[$dtoClass] ??= true;
                    $dtoClasses[$dtoClass] = true;
                }
                foreach ($resource->getOperations() ?? [] as $operationName => $operation) {
                    ++$visited;
                    foreach ($this->expressionsOf($operation) as $attribute => $expression) {
                        $found[] = \sprintf('%s, opération %s : %s = %s', $resourceClass, $operationName, $attribute, $expression);
                    }
                    $links += $this->collectNested(
                        $operation,
                        \sprintf('%s, opération %s', $resourceClass, $operationName),
                        $found,
                    );
                    foreach ($this->dtoClassesOf($operation) as $dtoClass) {
                        $queue[$dtoClass] ??= true;
                        $dtoClasses[$dtoClass] = true;
                    }
                }
            }
        }

        // Les propriétés ne dépendent pas des opérations : `ApiProperty` porte son propre `security`. La file
        // grandit pendant le parcours (un objet imbriqué y entre), d'où l'index et non un `foreach` sur une copie.
        $read = [];
        while (null !== ($class = array_key_first(array_diff_key($queue, $read)))) {
            $read[$class] = true;
            foreach ($propertyNames->create($class) as $propertyName) {
                ++$properties;
                $property = $propertyMetadata->create($class, $propertyName);
                foreach ($this->propertyExpressions($property) as $attribute => $expression) {
                    $found[] = \sprintf('%s, propriété %s : %s = %s', $class, $propertyName, $attribute, $expression);
                }
                foreach ($this->nestedClassesOf($property) as $nested) {
                    if (!isset($queue[$nested])) {
                        $queue[$nested] = true;
                        $nestedClasses[$nested] = true;
                    }
                }
            }
        }

        // Une garde qui ne parcourt rien rougirait jamais. Les paramètres ne sont pas comptés : aucune
        // ressource de `src/` n'en déclare peut-être, une garde qui l'exigerait rougirait pour rien.
        self::assertGreaterThan(0, $visited, 'Aucune opération parcourue : la garde ne verrait rien.');
        self::assertGreaterThan(0, $links, 'Aucun lien parcouru : la garde ne verrait rien des `uriVariables`.');
        self::assertGreaterThan(0, $properties, 'Aucune propriété parcourue : la garde ne verrait rien des `ApiProperty`.');
        // `src/` déclare un DTO d'entrée (`BackofficeUserInviteInput`) : sans lui, la lecture des DTO ne verrait rien.
        self::assertNotSame([], $dtoClasses, 'Aucune classe `input`/`output` parcourue : la garde ne verrait rien des DTO.');
        // Et `src/` imbrique des objets `App\` (`AboutCardResource`, `WatchedProductResource`… : neuf à R88) :
        // un parcours qui n'en suivrait aucun passerait ce test sans rien lire.
        self::assertNotSame([], $nestedClasses, 'Aucun objet imbriqué parcouru : la garde ne verrait rien des classes atteintes par un type.');
        self::assertSame(
            [],
            $found,
            "Toute autorisation de /api est une règle d'access_control de security.yaml, jamais un `security:` d'API Platform "
            ."(attribut, YAML ou XML) : PublicRouteIgnoresRefusedSessionListener tient « route publique = route sans règle ».\n - "
            .implode("\n - ", $found),
        );
    }

    /**
     * @param array<string, string> $expected attribut => expression que la garde doit relever
     */
    #[DataProvider('metadataThatCarrySecurity')]
    public function testTheCheckSeesEachSecurityExpression(Metadata $metadata, array $expected): void
    {
        self::assertSame($expected, $this->expressionsOf($metadata));
    }

    /**
     * @return iterable<string, array{Metadata, array<string, string>}>
     */
    public static function metadataThatCarrySecurity(): iterable
    {
        yield 'security d\'une opération' => [new Get(security: 'is_granted("ROLE_USER")'), ['security' => 'is_granted("ROLE_USER")']];
        yield 'securityPostDenormalize' => [
            new Put(securityPostDenormalize: 'object.owner == user'),
            ['securityPostDenormalize' => 'object.owner == user'],
        ];
        yield 'securityPostValidation' => [
            new Post(securityPostValidation: 'is_granted("ROLE_X")'),
            ['securityPostValidation' => 'is_granted("ROLE_X")'],
        ];
        yield 'security d\'une ressource' => [
            new ApiResource(security: 'is_granted("ROLE_USER")'),
            ['security' => 'is_granted("ROLE_USER")'],
        ];
        yield 'les trois à la fois' => [
            new Put(security: 'a', securityPostDenormalize: 'b', securityPostValidation: 'c'),
            ['security' => 'a', 'securityPostDenormalize' => 'b', 'securityPostValidation' => 'c'],
        ];
        yield 'aucune expression' => [new Get(), []];
    }

    /**
     * @param array<string, string> $expected attribut => expression que la garde doit relever
     */
    #[DataProvider('parametersThatCarrySecurity')]
    public function testTheCheckSeesEachParameterExpression(Parameter $parameter, array $expected): void
    {
        self::assertSame($expected, $this->parameterExpressions($parameter));
    }

    /**
     * @return iterable<string, array{Parameter, array<string, string>}>
     */
    public static function parametersThatCarrySecurity(): iterable
    {
        yield 'security d\'un paramètre' => [new QueryParameter(security: 'a'), ['security' => 'a']];
        // Link étend Parameter : un lien (uriVariables, links) porte la même clé.
        yield 'security d\'un lien' => [new Link(security: 'b'), ['security' => 'b']];
        yield 'aucune expression' => [new QueryParameter(), []];
    }

    /**
     * @param array<string, string> $expected attribut => expression que la garde doit relever
     */
    #[DataProvider('propertiesThatCarrySecurity')]
    public function testTheCheckSeesEachPropertyExpression(ApiProperty $property, array $expected): void
    {
        self::assertSame($expected, $this->propertyExpressions($property));
    }

    /**
     * @return iterable<string, array{ApiProperty, array<string, string>}>
     */
    public static function propertiesThatCarrySecurity(): iterable
    {
        yield 'security d\'une propriété' => [new ApiProperty(security: 'a'), ['security' => 'a']];
        yield 'securityPostDenormalize' => [
            new ApiProperty(securityPostDenormalize: 'b'),
            ['securityPostDenormalize' => 'b'],
        ];
        yield 'aucune expression' => [new ApiProperty(), []];
    }

    /**
     * @param list<class-string> $expected les classes de `App\` que le type natif de la propriété atteint
     */
    #[DataProvider('propertiesThatNestObjects')]
    public function testTheCheckReachesNestedClasses(ApiProperty $property, array $expected): void
    {
        self::assertSame($expected, $this->nestedClassesOf($property));
    }

    /**
     * @return iterable<string, array{ApiProperty, list<class-string>}>
     */
    public static function propertiesThatNestObjects(): iterable
    {
        yield 'un objet' => [new ApiProperty(nativeType: Type::object(self::class)), [self::class]];
        yield 'une collection d\'objets' => [
            new ApiProperty(nativeType: Type::list(Type::object(self::class))),
            [self::class],
        ];
        yield 'un objet nullable' => [new ApiProperty(nativeType: Type::nullable(Type::object(self::class))), [self::class]];
        yield 'une union' => [
            new ApiProperty(nativeType: Type::union(Type::object(self::class), Type::string())),
            [self::class],
        ];
        yield 'un type scalaire' => [new ApiProperty(nativeType: Type::string()), []];
        yield 'un objet hors App\\' => [new ApiProperty(nativeType: Type::object(DateTimeImmutable::class)), []];
        yield 'une énumération' => [new ApiProperty(nativeType: Type::enum(Locale::class)), []];
        yield 'aucun type natif' => [new ApiProperty(), []];
    }

    public function testANonLinkUriVariableIsReported(): void
    {
        $found = [];
        $links = $this->collectNested(new Get(uriVariables: ['id' => 'App\Foo']), 'contexte', $found);

        self::assertSame(0, $links);
        self::assertCount(1, $found);
        self::assertStringContainsString('contexte, lien id', $found[0]);
        self::assertStringContainsString('n\'est pas un Link compilé', $found[0]);
    }

    /**
     * Les classes `input` et `output` d'une ressource ou d'une opération. API Platform normalise
     * `input: Foo::class` en `['class' => Foo::class]` ; `false` (pas de DTO) et `null` n'en portent pas.
     *
     * @return list<class-string>
     */
    private function dtoClassesOf(Metadata $element): array
    {
        $classes = [];
        foreach ([$element->getInput(), $element->getOutput()] as $dto) {
            if (\is_array($dto) && isset($dto['class']) && \is_string($dto['class']) && class_exists($dto['class'])) {
                $classes[] = $dto['class'];
            }
        }

        return $classes;
    }

    /**
     * Les classes de `App\` que le type natif d'une propriété atteint : un objet, la valeur d'une collection,
     * un type nullable, une union ou une intersection, à toute profondeur. Les énumérations n'ont pas de propriété.
     *
     * @return list<class-string>
     */
    private function nestedClassesOf(ApiProperty $property): array
    {
        $type = $property->getNativeType();

        return null === $type ? [] : array_values(array_unique($this->appClassesIn($type)));
    }

    /**
     * @return list<class-string>
     */
    private function appClassesIn(Type $type): array
    {
        $classes = [];
        if ($type instanceof ObjectType && !$type instanceof EnumType && str_starts_with($type->getClassName(), 'App\\')) {
            /** @var class-string $className */
            $className = $type->getClassName();
            $classes[] = $className;
        }
        $inner = [];
        if ($type instanceof CompositeTypeInterface) {
            $inner = $type->getTypes();
        } elseif ($type instanceof WrappingTypeInterface) {
            $inner = [$type->getWrappedType()];
        }
        if ($type instanceof CollectionType) {
            $inner[] = $type->getCollectionValueType();
        }
        foreach ($inner as $innerType) {
            $classes = [...$classes, ...$this->appClassesIn($innerType)];
        }

        return $classes;
    }

    /**
     * @return array<string, string> attribut => expression, pour ceux que l'élément déclare
     */
    private function expressionsOf(Metadata $metadata): array
    {
        return array_filter([
            'security' => $metadata->getSecurity(),
            'securityPostDenormalize' => $metadata->getSecurityPostDenormalize(),
            'securityPostValidation' => $metadata->getSecurityPostValidation(),
        ], static fn (?string $expression): bool => null !== $expression);
    }

    /**
     * Les paramètres et les liens (`uriVariables`) d'une ressource ou d'une opération.
     *
     * @param list<string> $found complété des constats, « <contexte>, paramètre|lien <nom> : security = <expr> »
     *
     * @return int le nombre de liens parcourus (garde-fou : `src/` en a)
     */
    private function collectNested(Metadata $element, string $context, array &$found): int
    {
        foreach ($element->getParameters() ?? [] as $name => $parameter) {
            foreach ($this->parameterExpressions($parameter) as $attribute => $expression) {
                $found[] = \sprintf('%s, paramètre %s : %s = %s', $context, $name, $attribute, $expression);
            }
        }

        $links = 0;
        // Compilées, les `uriVariables` sont des `Link` d'API Platform, qui étendent `Parameter` ; un
        // tableau non compilé n'en porte pas. `getLinks()` n'est pas parcouru : ce sont des `WebLink` de
        // Symfony (relations de l'en-tête HTTP `Link`), sans `security`.
        $candidates = [];
        if ($element instanceof ApiResource || $element instanceof HttpOperation) {
            foreach ((array) $element->getUriVariables() as $name => $link) {
                $candidates[(string) $name] = $link;
            }
        }
        foreach ($candidates as $name => $link) {
            if (!$link instanceof Link) {
                // Une variable non compilée n'a pas de `security` que la garde puisse lire : le dire plutôt que
                // la sauter sans bruit (un `continue` ici laisserait passer une sécurité posée par un autre moyen).
                $found[] = \sprintf('%s, lien %s : n\'est pas un Link compilé, sa sécurité ne serait pas lue', $context, $name);

                continue;
            }
            ++$links;
            foreach ($this->parameterExpressions($link) as $attribute => $expression) {
                $found[] = \sprintf('%s, lien %s : %s = %s', $context, $name, $attribute, $expression);
            }
        }

        return $links;
    }

    /**
     * `Link` étend `Parameter` : la même clé `security` vaut pour un paramètre et pour un lien.
     *
     * @return array<string, string> attribut => expression, pour ceux que l'élément déclare
     */
    private function parameterExpressions(Parameter $parameter): array
    {
        $security = $parameter->getSecurity();

        return null === $security ? [] : ['security' => (string) $security];
    }

    /**
     * @return array<string, string> attribut => expression, pour ceux que la propriété déclare
     */
    private function propertyExpressions(ApiProperty $property): array
    {
        return array_filter([
            'security' => $property->getSecurity(),
            'securityPostDenormalize' => $property->getSecurityPostDenormalize(),
        ], static fn (?string $expression): bool => null !== $expression);
    }
}
