<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Fixtures\Traits;

use App\Security\User\Application\CpgUserAdministratorInterface;
use SensitiveParameter;
use Symfony\Component\Uid\Uuid;

/**
 * Fixture de {@see \App\Tests\Security\User\PlainPasswordInputsTest} (issue
 * #421) : un trait qui appelle un cas d'usage recevant un mot de passe. La
 * classe qui l'utilise ne le cite que par `use` : seul le fichier du trait dit
 * qu'elle en est une voie d'entrée.
 */
trait ChangesPasswordThroughATraitFixture
{
    public function forward(CpgUserAdministratorInterface $administrator, Uuid $id, #[SensitiveParameter] string $newPlainPassword): void
    {
        $administrator->changePassword($id, $newPlainPassword);
    }
}
