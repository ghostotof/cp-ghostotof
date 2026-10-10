<?php

declare(strict_types=1);

namespace App\Tests\Security\Fixtures\BeyondClasses;

/**
 * Fixture de {@see \App\Tests\Security\SecretParametersTest} (issue #421) :
 * une interface qu'aucune classe n'implémente, que la réflexion ne pouvait
 * atteindre par une implémentation.
 */
interface SecretParametersOrphanInterfaceFixture
{
    public function authenticate(string $apiKey): bool;
}
