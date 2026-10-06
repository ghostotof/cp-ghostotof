<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\EventListener\ErrorListener;

/**
 * Le contrat que CompiledExceptionConfig passe avec les internes de
 * l'ErrorListener du noyau (issue #357).
 *
 * Il en lit deux par réflexion, plutôt que de réimplémenter ce que fait le
 * noyau : le mapping compilé de `framework.exceptions`, et la résolution d'un
 * attribut hérité (classes parentes et interfaces). Ni l'un ni l'autre n'est
 * une API publique. Une montée de version de Symfony qui les renomme ou en
 * change la forme rougit ici, avec le membre en cause, avant que les
 * garde-fous de log_level ne lisent une forme qu'ils ne comprennent plus.
 */
final class ErrorListenerInternalsTest extends TestCase
{
    public function testTheCompiledMappingIsAnArrayProperty(): void
    {
        $property = new \ReflectionProperty(ErrorListener::class, 'exceptionsMapping');

        self::assertSame('array', (string) $property->getType());
        self::assertFalse($property->isStatic());
    }

    public function testTheInheritedAttributeResolverTakesAClassAndAnAttribute(): void
    {
        $method = new \ReflectionMethod(ErrorListener::class, 'getInheritedAttribute');

        self::assertFalse($method->isStatic());
        self::assertSame(
            ['class' => 'string', 'attribute' => 'string'],
            array_combine(
                array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), $method->getParameters()),
                array_map(static fn (\ReflectionParameter $parameter): string => (string) $parameter->getType(), $method->getParameters()),
            ),
        );
        self::assertSame('?object', (string) $method->getReturnType());
    }
}
