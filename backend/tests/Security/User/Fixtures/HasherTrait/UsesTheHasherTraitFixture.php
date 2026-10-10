<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Fixtures\HasherTrait;

/**
 * Fixture de {@see \App\Tests\Security\User\PlainPasswordInputsTest} (issue
 * #421) : hache par {@see HashesThroughATraitFixture}, sans nommer le hasher.
 */
final class UsesTheHasherTraitFixture
{
    use HashesThroughATraitFixture;
}
