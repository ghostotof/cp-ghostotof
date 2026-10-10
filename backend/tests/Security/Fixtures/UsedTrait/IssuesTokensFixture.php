<?php

declare(strict_types=1);

namespace App\Tests\Security\Fixtures\UsedTrait;

/**
 * Fixture de {@see \App\Tests\Security\SecretParametersTest} (issue #421) :
 * utilise {@see IssuesTokensFixtureTrait}.
 */
final class IssuesTokensFixture
{
    use IssuesTokensFixtureTrait;
}
