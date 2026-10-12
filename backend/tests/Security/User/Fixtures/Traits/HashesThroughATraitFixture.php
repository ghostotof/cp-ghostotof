<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Fixtures\Traits;

use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

/**
 * Fixture de {@see \App\Tests\Security\User\PlainPasswordInputsTest} (issue
 * #421) : un trait qui nomme le hasher. La classe qui l'utilise ne le cite
 * que par `use` : seul le fichier du trait dit qu'elle hache.
 */
trait HashesThroughATraitFixture
{
    public function hashConstant(UserPasswordHasherInterface $hasher, PasswordAuthenticatedUserInterface $user): string
    {
        return $hasher->hashPassword($user, 'constante');
    }
}
