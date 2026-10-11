<?php

declare(strict_types=1);

namespace App\Tests\Security\Fixtures\Prototypes;

/**
 * Fixture de {@see \App\Tests\Security\SecretParametersTest} (issue #428) :
 * une fille dont le constructeur et une méthode privée homonymes reçoivent
 * autre chose qu'un secret à la même position.
 */
final class SecretParametersChildFixture extends SecretParametersParentFixture
{
    public function __construct(string $title)
    {
        parent::__construct($title);
    }

    private function sign(string $label): string
    {
        return $label;
    }

    public function labelled(): string
    {
        return $this->sign('libellé');
    }
}
