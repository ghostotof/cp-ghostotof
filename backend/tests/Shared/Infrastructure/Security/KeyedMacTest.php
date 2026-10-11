<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Security;

use App\Shared\Infrastructure\Security\EmptyApplicationSecretException;
use App\Shared\Infrastructure\Security\KeyedMac;
use App\Shared\Infrastructure\Security\MacEncoding;
use App\Shared\Infrastructure\Security\SecretKeyDerivation;
use App\Tests\Support\TestKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le HMAC sous clé dérivée d'`APP_SECRET`, écrit une fois (issue #487) :
 * dérivation, algorithme et comparaison en temps constant.
 */
final class KeyedMacTest extends TestCase
{
    private const string CONTEXT = 'cp-ghostotof/keyed-mac-test/v1';

    /**
     * Le calcul est exactement celui que les quatre usages faisaient à la
     * main avant #487 : `hash_hmac('sha256', …)` sous la clé de
     * SecretKeyDerivation. Toute autre sortie serait une rotation de fait.
     */
    public function testAHexMacIsTheHmacSha256UnderTheDerivedKey(): void
    {
        self::assertSame(
            hash_hmac('sha256', 'donnée', SecretKeyDerivation::derive(TestKeys::material(), self::CONTEXT)),
            KeyedMac::fromSecret(TestKeys::material(), self::CONTEXT, MacEncoding::Hex)->sign('donnée'),
        );
    }

    public function testABinaryMacIsTheRawHmacSha256UnderTheDerivedKey(): void
    {
        self::assertSame(
            hash_hmac('sha256', 'donnée', SecretKeyDerivation::derive(TestKeys::material(), self::CONTEXT), true),
            KeyedMac::fromSecret(TestKeys::material(), self::CONTEXT, MacEncoding::Binary)->sign('donnée'),
        );
    }

    #[DataProvider('encodings')]
    public function testItsOwnMacVerifies(MacEncoding $encoding): void
    {
        $mac = KeyedMac::fromSecret(TestKeys::material(), self::CONTEXT, $encoding);

        self::assertTrue($mac->verifies($mac->sign('donnée'), 'donnée'));
    }

    #[DataProvider('encodings')]
    public function testAMacOfOtherDataIsRefused(MacEncoding $encoding): void
    {
        $mac = KeyedMac::fromSecret(TestKeys::material(), self::CONTEXT, $encoding);

        self::assertFalse($mac->verifies($mac->sign('donnée'), 'données'));
    }

    #[DataProvider('encodings')]
    public function testAMacUnderAnotherContextIsRefused(MacEncoding $encoding): void
    {
        $other = KeyedMac::fromSecret(TestKeys::material(), 'cp-ghostotof/keyed-mac-test/v2', $encoding);

        self::assertFalse(KeyedMac::fromSecret(TestKeys::material(), self::CONTEXT, $encoding)->verifies($other->sign('donnée'), 'donnée'));
    }

    #[DataProvider('encodings')]
    public function testAMacUnderAnotherSecretIsRefused(MacEncoding $encoding): void
    {
        $other = KeyedMac::fromSecret(TestKeys::material('autre'), self::CONTEXT, $encoding);

        self::assertFalse(KeyedMac::fromSecret(TestKeys::material(), self::CONTEXT, $encoding)->verifies($other->sign('donnée'), 'donnée'));
    }

    /**
     * Une valeur vide ne vaut jamais un MAC : `hash_equals('', '')` est vrai,
     * mais le MAC calculé, lui, n'est jamais vide.
     */
    #[DataProvider('encodings')]
    public function testAnEmptyMacIsRefused(MacEncoding $encoding): void
    {
        self::assertFalse(KeyedMac::fromSecret(TestKeys::material(), self::CONTEXT, $encoding)->verifies('', ''));
    }

    /**
     * L'encodage est fixé à la création : le même MAC dans l'autre encodage
     * est refusé, l'appelant ne peut pas signer d'une façon et vérifier de
     * l'autre.
     */
    public function testAMacInTheOtherEncodingIsRefused(): void
    {
        $hex = KeyedMac::fromSecret(TestKeys::material(), self::CONTEXT, MacEncoding::Hex);
        $binary = KeyedMac::fromSecret(TestKeys::material(), self::CONTEXT, MacEncoding::Binary);

        self::assertFalse($hex->verifies($binary->sign('donnée'), 'donnée'));
        self::assertFalse($binary->verifies($hex->sign('donnée'), 'donnée'));
    }

    /**
     * La dérivation a lieu à la création : un secret vide est refusé là, et
     * l'appelant qui veut un refus au premier usage crée l'objet à ce
     * moment-là.
     */
    public function testAnEmptySecretIsRefusedAtCreation(): void
    {
        $this->expectException(EmptyApplicationSecretException::class);

        KeyedMac::fromSecret('', self::CONTEXT, MacEncoding::Hex);
    }

    /**
     * @return iterable<string, array{MacEncoding}>
     */
    public static function encodings(): iterable
    {
        foreach (MacEncoding::cases() as $encoding) {
            yield $encoding->name => [$encoding];
        }
    }
}
