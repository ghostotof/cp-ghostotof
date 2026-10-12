<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Fixtures\Traits;

use SensitiveParameter;
use Symfony\Component\Uid\Uuid;

/**
 * Fixture de {@see \App\Tests\Security\User\PlainPasswordInputsTest} (issue
 * #421) : appelle `changePassword()` sur une propriété de la classe qui
 * l'utilise, sans nommer l'interface — c'est la classe qui la nomme.
 */
trait CallsThroughAPropertyFixture
{
    public function forwardThroughProperty(Uuid $id, #[SensitiveParameter] string $newPlainPassword): void
    {
        $this->administrator->changePassword($id, $newPlainPassword);
    }
}
