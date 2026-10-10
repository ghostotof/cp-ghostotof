<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Tests\Shared\Fixtures\DeclaredClasses\DeclaredFixtureAbstractClass;
use App\Tests\Shared\Fixtures\DeclaredClasses\DeclaredFixtureClass;
use App\Tests\Shared\Fixtures\DeclaredClasses\DeclaredFixtureEnum;
use App\Tests\Shared\Fixtures\DeclaredClasses\DeclaredFixtureInterface;
use App\Tests\Shared\Fixtures\DeclaredClasses\DeclaredFixtureTrait;
use App\Tests\Support\DeclaredClasses;
use PHPUnit\Framework\TestCase;

/**
 * Ce que {@see DeclaredClasses} recense, sur un fichier hors PSR-4 qui déclare
 * une chose de chaque sorte (issue #421). Deux recensements, qu'un garde-fou
 * choisit explicitement : les classes seules, pour qui raisonne sur des
 * classes (une exception levée, un `RetryAfterAware` instancié), et toutes
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
            DeclaredFixtureTrait::class,
        ], DeclaredClasses::classLikes(self::FIXTURES));
    }

    /**
     * Une interface qui étend le type n'est pas une classe qui l'implémente :
     * `implementing()` reste sur les classes, que ses appelants instancient
     * ou lèvent.
     */
    public function testImplementingReturnsClassesOnly(): void
    {
        self::assertSame([DeclaredFixtureAbstractClass::class, DeclaredFixtureClass::class], DeclaredClasses::implementing(self::FIXTURES, DeclaredFixtureInterface::class));
    }
}
