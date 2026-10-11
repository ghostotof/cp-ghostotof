<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Security;

use App\Shared\Infrastructure\Security\EmptyApplicationSecretException;
use App\Shared\Infrastructure\Security\SecretKeyDerivation;
use App\Tests\Support\TestKeys;
use PHPUnit\Framework\TestCase;

/**
 * Une clé par usage d'`APP_SECRET` (issue #455) : aucun HMAC calculé pour un
 * usage ne vaut pour un autre, et aucun n'est calculé sous le secret brut.
 */
final class SecretKeyDerivationTest extends TestCase
{
    public function testTwoContextsGiveTwoKeys(): void
    {
        self::assertNotSame(
            SecretKeyDerivation::derive(TestKeys::material(), 'cp-ghostotof/a/v1'),
            SecretKeyDerivation::derive(TestKeys::material(), 'cp-ghostotof/b/v1'),
        );
    }

    public function testTheSameContextAlwaysGivesTheSameKey(): void
    {
        self::assertSame(
            SecretKeyDerivation::derive(TestKeys::material(), 'cp-ghostotof/a/v1'),
            SecretKeyDerivation::derive(TestKeys::material(), 'cp-ghostotof/a/v1'),
        );
    }

    public function testTheKeyIsNeitherTheSecretNorAnHmacOfTheContextUnderIt(): void
    {
        $key = SecretKeyDerivation::derive(TestKeys::material(), 'cp-ghostotof/a/v1');

        self::assertSame(32, \strlen($key));
        self::assertNotSame(TestKeys::material(), $key);
        self::assertNotSame(hash_hmac('sha256', 'cp-ghostotof/a/v1', TestKeys::material(), true), $key);
    }

    public function testTheKeyDependsOnTheSecret(): void
    {
        self::assertNotSame(
            SecretKeyDerivation::derive(TestKeys::material(), 'cp-ghostotof/a/v1'),
            SecretKeyDerivation::derive(TestKeys::material('autre'), 'cp-ghostotof/a/v1'),
        );
    }

    public function testAnEmptySecretIsRefused(): void
    {
        $this->expectException(EmptyApplicationSecretException::class);

        SecretKeyDerivation::derive('', 'cp-ghostotof/a/v1');
    }
}
