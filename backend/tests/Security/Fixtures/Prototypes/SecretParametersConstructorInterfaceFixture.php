<?php

declare(strict_types=1);

namespace App\Tests\Security\Fixtures\Prototypes;

/**
 * Fixture de {@see \App\Tests\Security\SecretParametersTest} (issue #428) :
 * le constructeur d'une interface est, lui, un prototype que PHP impose.
 */
interface SecretParametersConstructorInterfaceFixture
{
    public function __construct(string $secret);
}
