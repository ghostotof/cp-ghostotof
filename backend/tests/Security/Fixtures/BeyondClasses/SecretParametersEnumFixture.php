<?php

declare(strict_types=1);

namespace App\Tests\Security\Fixtures\BeyondClasses;

use SensitiveParameter;

/**
 * Fixture de {@see \App\Tests\Security\SecretParametersTest} (issue #421) :
 * un enum, que le recensement des seules classes ne voyait pas. Un jeton
 * exposé, un caché.
 */
enum SecretParametersEnumFixture: string
{
    case Bearer = 'bearer';

    public static function fromToken(string $token): self
    {
        return self::tryFrom(strtolower($token)) ?? self::Bearer;
    }

    public function sign(#[SensitiveParameter] string $signingKey): string
    {
        return hash_hmac('sha256', $this->value, $signingKey);
    }
}
