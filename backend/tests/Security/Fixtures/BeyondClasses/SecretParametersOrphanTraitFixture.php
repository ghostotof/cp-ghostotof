<?php

declare(strict_types=1);

namespace App\Tests\Security\Fixtures\BeyondClasses;

/**
 * Fixture de {@see \App\Tests\Security\SecretParametersTest} (issue #421) :
 * un trait qu'aucune classe n'utilise, que la réflexion ne pouvait atteindre
 * par une classe utilisatrice.
 */
// @phpstan-ignore trait.unused (sans utilisateur par construction : c'est ce que la fixture éprouve)
trait SecretParametersOrphanTraitFixture
{
    public function decrypt(string $secret): string
    {
        return strrev($secret);
    }
}
