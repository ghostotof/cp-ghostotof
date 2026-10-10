<?php

declare(strict_types=1);

namespace App\Tests\Shared\Fixtures\RegexEndAnchor;

/**
 * Fixture de {@see \App\Tests\Shared\RegexEndAnchorTest} (issue #421) : porte
 * la contrainte de {@see RegexOnTraitFixture}.
 */
final class UsesTheRegexTraitFixture
{
    use RegexOnTraitFixture;
}
