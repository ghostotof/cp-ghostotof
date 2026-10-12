<?php

declare(strict_types=1);

namespace App\Tests\Security\Fixtures;

use SensitiveParameter;

/**
 * Le prototype que {@see SecretParametersFixture} implémente sous un autre nom
 * de paramètre (revue de #414) : l'attribut posé ici n'a aucun effet à
 * l'exécution, seul compte celui de l'implémentation.
 */
interface SecretParametersFixtureInterface
{
    public function verify(#[SensitiveParameter] string $clearToken): bool;
}
