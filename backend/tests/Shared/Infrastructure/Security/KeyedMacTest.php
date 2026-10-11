<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Security;

use App\Shared\Infrastructure\Security\EmptyApplicationSecretException;
use App\Shared\Infrastructure\Security\KeyedMac;
use App\Shared\Infrastructure\Security\MacEncoding;
use App\Shared\Infrastructure\Security\SecretKeyDerivation;
use App\Tests\Support\Dumps;
use App\Tests\Support\TestKeys;
use Exception;
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

    /**
     * Le cache découpe sa valeur stockée sur cette longueur : elle suit
     * l'algorithme, que seule cette classe connaît.
     */
    public function testABinaryMacIsBinaryLengthBytesLong(): void
    {
        self::assertSame(
            KeyedMac::BINARY_LENGTH,
            \strlen(KeyedMac::fromSecret(TestKeys::material(), self::CONTEXT, MacEncoding::Binary)->sign('donnée')),
        );
    }

    /**
     * Une donnée vide se signe comme une autre : jamais un MAC vide, que
     * `hash_equals('', '')` accepterait.
     */
    #[DataProvider('encodings')]
    public function testAnEmptyDataGetsANonEmptyMacThatVerifies(MacEncoding $encoding): void
    {
        $mac = KeyedMac::fromSecret(TestKeys::material(), self::CONTEXT, $encoding);

        self::assertSame(MacEncoding::Hex === $encoding ? 2 * KeyedMac::BINARY_LENGTH : KeyedMac::BINARY_LENGTH, \strlen($mac->sign('')));
        self::assertTrue($mac->verifies($mac->sign(''), ''));
        self::assertFalse($mac->verifies('', ''));
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
     * Relecture de #487 : la clé dérivée ne sort par aucun des canaux qui
     * affichent un objet — `dump()` et le profiler (VarDumper, qui lit les
     * propriétés privées même en présence d'un `__debugInfo()`), un contexte
     * de journal, une sortie de débogage.
     */
    public function testTheDerivedKeyNeverShowsWhenTheObjectIsDumped(): void
    {
        $key = SecretKeyDerivation::derive(TestKeys::material(), self::CONTEXT);
        $mac = KeyedMac::fromSecret(TestKeys::material(), self::CONTEXT, MacEncoding::Hex);

        // Un échec ne cite que les canaux : afficher le dump afficherait la clé.
        self::assertSame([], Dumps::channelsShowing($mac, $key), 'Canaux qui affichent la clé dérivée.');
        self::assertSame([], Dumps::channelsShowing($mac, TestKeys::material()), 'Canaux qui affichent le secret.');
    }

    /**
     * Une file, un cache ou une session ne garde jamais la clé : la
     * sérialisation est refusée.
     */
    public function testAKeyedMacCannotBeSerialized(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Serialization of');

        serialize(KeyedMac::fromSecret(TestKeys::material(), self::CONTEXT, MacEncoding::Hex));
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
