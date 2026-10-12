<?php

declare(strict_types=1);

namespace App\Tests\Security\Fixtures\UsedTrait;

/**
 * Fixture de {@see \App\Tests\Security\SecretParametersTest} (issue #421) : un
 * trait utilisé, dont la réflexion montre aussi la méthode dans la classe qui
 * l'utilise. La garde ne doit signaler le jeton exposé qu'une fois.
 */
trait IssuesTokensFixtureTrait
{
    public function issue(string $token): string
    {
        return strrev($token);
    }
}
