<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Tests\Shared\Fixtures\DeclaredClasses\DeclaredFixtureAbstractClass;
use App\Tests\Shared\Fixtures\DeclaredClasses\DeclaredFixtureClass;
use App\Tests\Shared\Fixtures\DeclaredClasses\DeclaredFixtureEnum;
use App\Tests\Shared\Fixtures\DeclaredClasses\DeclaredFixtureInterface;
use App\Tests\Shared\Fixtures\DeclaredClasses\DeclaredFixtureSubInterface;
use App\Tests\Shared\Fixtures\DeclaredClasses\DeclaredFixtureTrait;
use App\Tests\Support\DeclaredClasses;
use PHPUnit\Framework\TestCase;

/**
 * Ce que {@see DeclaredClasses} recense, sur un fichier hors PSR-4 qui déclare
 * une chose de chaque sorte (issue #421). Deux recensements, qu'un garde-fou
 * choisit explicitement : les classes seules, pour qui raisonne sur des
 * classes (une exception levée, une exception de quota instanciée), et toutes
 * les déclarations nommées, pour qui lit des signatures ou des attributs.
 */
final class DeclaredClassesTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/Fixtures/DeclaredClasses';

    public function testClassesAreTheNamedClassesOnly(): void
    {
        self::assertSame([DeclaredFixtureAbstractClass::class, DeclaredFixtureClass::class], DeclaredClasses::classes(self::FIXTURES));
    }

    /**
     * Ni la classe anonyme, ni `X::class`, ni la méthode nommée `enum` ne
     * sont des déclarations.
     */
    public function testClassLikesAreEveryNamedDeclaration(): void
    {
        self::assertSame([
            DeclaredFixtureAbstractClass::class,
            DeclaredFixtureClass::class,
            DeclaredFixtureEnum::class,
            DeclaredFixtureInterface::class,
            DeclaredFixtureSubInterface::class,
            DeclaredFixtureTrait::class,
        ], DeclaredClasses::classLikes(self::FIXTURES));
    }

    /**
     * La réflexion montre les membres d'un trait dans la classe qui l'utilise :
     * un recensement qui lit aussi le trait pour lui-même les verrait deux
     * fois.
     */
    public function testTraitsUsedByNamesTheTraitsTheDeclarationsUse(): void
    {
        self::assertSame([DeclaredFixtureTrait::class], DeclaredClasses::traitsUsedBy(DeclaredClasses::classLikes(self::FIXTURES)));
    }

    /**
     * Ce que lit un garde-fou de signatures : chaque déclaration, sauf le
     * trait utilisé, déjà vu dans sa classe.
     */
    public function testWithoutUsedTraitsKeepsEveryDeclarationButTheUsedTrait(): void
    {
        self::assertSame([
            DeclaredFixtureAbstractClass::class,
            DeclaredFixtureClass::class,
            DeclaredFixtureEnum::class,
            DeclaredFixtureInterface::class,
            DeclaredFixtureSubInterface::class,
        ], DeclaredClasses::withoutUsedTraits(DeclaredClasses::classLikes(self::FIXTURES)));
    }

    /**
     * Une interface qui étend le type n'est pas une classe qui l'implémente
     * (`DeclaredFixtureSubInterface`, pour laquelle `is_subclass_of()` est
     * vrai) : `implementing()` reste sur les classes, que ses appelants
     * instancient ou lèvent.
     */
    public function testImplementingReturnsClassesOnly(): void
    {
        self::assertSame([DeclaredFixtureAbstractClass::class, DeclaredFixtureClass::class], DeclaredClasses::implementing(self::FIXTURES, DeclaredFixtureInterface::class));
    }
}
