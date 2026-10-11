<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Fixtures\Traits;

/**
 * Fixture de {@see \App\Tests\Security\User\PlainPasswordInputsTest} (issue
 * #421) : appelle `changePassword()` par {@see ChangesPasswordThroughATraitFixture}.
 */
final class UsesTheCallerTraitFixture
{
    use ChangesPasswordThroughATraitFixture;
}
