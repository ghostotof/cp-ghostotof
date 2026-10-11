<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Security;

use App\Shared\Infrastructure\Security\EmptyApplicationSecretException;
use App\Shared\Infrastructure\Security\InvalidKeyContextException;
use App\Shared\Infrastructure\Security\SecretKeyDerivation;
use App\Tests\Support\TestKeys;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * Un contexte hors du format `cp-ghostotof/<usage>/v<n>` est refusé
     * (issue #487) : un contexte vide, ou celui d'un autre usage écrit
     * autrement, donnerait une clé que rien ne distingue.
     */
    #[DataProvider('malformedContexts')]
    public function testAMalformedContextIsRefused(string $context): void
    {
        $this->expectException(InvalidKeyContextException::class);

        SecretKeyDerivation::derive(TestKeys::material(), $context);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedContexts(): iterable
    {
        yield 'vide' => [''];
        yield 'sans préfixe' => ['cache-value/v1'];
        yield 'sans version' => ['cp-ghostotof/cache-value'];
        yield 'version zéro' => ['cp-ghostotof/cache-value/v0'];
        yield 'majuscules' => ['cp-ghostotof/Cache-Value/v1'];
        yield 'usage vide' => ['cp-ghostotof//v1'];
        yield 'saut de ligne final' => ["cp-ghostotof/cache-value/v1\n"];
    }

    public function testEveryContextOfTheFourUsesIsWellFormed(): void
    {
        foreach (['cp-ghostotof/csrf-cookie/v1', 'cp-ghostotof/login-identifier-fingerprint/v1', 'cp-ghostotof/messenger-envelope/v2', 'cp-ghostotof/cache-value/v1'] as $context) {
            self::assertSame(32, \strlen(SecretKeyDerivation::derive(TestKeys::material(), $context)));
        }
    }
}
