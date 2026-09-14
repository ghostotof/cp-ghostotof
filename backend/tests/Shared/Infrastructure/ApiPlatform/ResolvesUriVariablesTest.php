<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\ApiPlatform;

use App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * `uriVariableUuid()` est `private` sur le trait, comme le reste de
 * `ResolvesUriVariables` : on le teste via une classe anonyme qui `use` le
 * trait et expose un point d'entrée public, à l'identique de n'importe quel
 * autre test de trait de ce projet.
 */
final class ResolvesUriVariablesTest extends TestCase
{
    public function testAValidUuidStringResolvesToTheEqualUuid(): void
    {
        $value = '019968a0-0000-7000-8000-000000000001';

        $resolver = $this->resolver();

        self::assertTrue(Uuid::fromString($value)->equals($resolver->resolve(['id' => $value])));
    }

    public function testANonUuidStringTriggersTheAssertion(): void
    {
        $this->expectException(\AssertionError::class);

        $this->resolver()->resolve(['id' => 'not-a-uuid']);
    }

    public function testAnAbsentKeyTriggersTheAssertion(): void
    {
        $this->expectException(\AssertionError::class);

        $this->resolver()->resolve([]);
    }

    private function resolver(): ResolvesUriVariablesTestResolver
    {
        return new class implements ResolvesUriVariablesTestResolver {
            use ResolvesUriVariables;

            /**
             * @param array<string, mixed> $uriVariables
             */
            public function resolve(array $uriVariables): Uuid
            {
                return $this->uriVariableUuid($uriVariables);
            }
        };
    }
}

/**
 * Point d'entrée public de la classe anonyme de test : PHPStan ne peut pas
 * typer le retour d'une méthode par une classe anonyme, d'où cette interface
 * dédiée au seul usage de ce test.
 */
interface ResolvesUriVariablesTestResolver
{
    /**
     * @param array<string, mixed> $uriVariables
     */
    public function resolve(array $uriVariables): Uuid;
}
