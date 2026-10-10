<?php

declare(strict_types=1);

namespace App\Tests\Shared\Fixtures\DeclaredClasses;

use Stringable;

/*
 * Fixture de App\Tests\Shared\DeclaredClassesTest (issue #421) : un fichier qui ne porte le nom
 * d'aucune des déclarations qu'il contient — une de chaque sorte —, et des
 * mots-clés qui n'en sont pas (une classe anonyme, `X::class`, une méthode
 * nommée `enum`).
 */

interface DeclaredFixtureInterface
{
    public function enum(): string;
}

interface DeclaredFixtureSubInterface extends DeclaredFixtureInterface
{
}

trait DeclaredFixtureTrait
{
    public function anonymous(): Stringable
    {
        return new class implements Stringable {
            public function __toString(): string
            {
                return DeclaredFixtureEnum::class;
            }
        };
    }
}

enum DeclaredFixtureEnum: string
{
    case Only = 'only';
}

abstract class DeclaredFixtureAbstractClass implements DeclaredFixtureInterface
{
}

final class DeclaredFixtureClass extends DeclaredFixtureAbstractClass
{
    use DeclaredFixtureTrait;

    public function enum(): string
    {
        return DeclaredFixtureEnum::Only->value;
    }
}
