<?php

declare(strict_types=1);

namespace App\Tests\Security\Fixtures\Prototypes;

/**
 * Fixture de {@see \App\Tests\Security\SecretParametersTest} (issue #428) :
 * une mère dont le constructeur et une méthode privée nomment un secret.
 */
abstract class SecretParametersParentFixture
{
    public function __construct(public readonly string $secret)
    {
    }

    private function sign(string $token): string
    {
        return $token;
    }

    public function signed(): string
    {
        return $this->sign($this->secret);
    }
}
