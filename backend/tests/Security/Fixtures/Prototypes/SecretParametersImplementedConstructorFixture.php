<?php

declare(strict_types=1);

namespace App\Tests\Security\Fixtures\Prototypes;

/**
 * Fixture de {@see \App\Tests\Security\SecretParametersTest} (issue #428) :
 * le secret du constructeur de l'interface, reçu sous un autre nom.
 */
final class SecretParametersImplementedConstructorFixture implements SecretParametersConstructorInterfaceFixture
{
    public function __construct(public readonly string $value)
    {
    }
}
