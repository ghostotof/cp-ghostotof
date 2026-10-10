<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Fixtures\Traits;

/**
 * Fixture de {@see \App\Tests\Security\User\PlainPasswordInputsTest} (issue
 * #421) : un champ de mot de passe déclaré par un trait. La réflexion le
 * montre aussi dans la classe qui l'utilise : la garde ne doit le compter
 * qu'une fois.
 */
trait CarriesAPasswordFieldFixture
{
    public ?string $password = null;
}
