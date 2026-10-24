<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Infrastructure\League;

use App\Mcp\Connection\Infrastructure\League\OAuthEncryptionKey;
use App\Shared\Infrastructure\Security\EmptyApplicationSecretException;
use App\Tests\Support\TestKeys;
use Defuse\Crypto\Crypto;
use Defuse\Crypto\Exception\WrongKeyOrModifiedCiphertextException;
use PHPUnit\Framework\TestCase;

/**
 * La clé qui chiffre les codes d'autorisation et les jetons de
 * rafraîchissement de league (#647) : dérivée d'`APP_SECRET` sous son propre
 * contexte (#455), jamais le secret lui-même — league accepterait une chaîne
 * et la prendrait pour un mot de passe defuse, le secret brut servant alors
 * de clé à un usage de plus.
 */
final class OAuthEncryptionKeyTest extends TestCase
{
    public function testTheEncryptionKeyIsDerivedNotTheSecretItself(): void
    {
        $secret = TestKeys::material();

        $key = OAuthEncryptionKey::fromSecret($secret);

        // HKDF-SHA256 sur 32 octets sous le contexte `…/oauth-encryption/v1`,
        // écrit ici en littéral : un contexte changé est une rotation, décidée,
        // jamais glissée par une refonte.
        self::assertSame(hash_hkdf('sha256', $secret, 32, 'cp-ghostotof/oauth-encryption/v1'), $key->getRawBytes());
        self::assertStringNotContainsString($secret, $key->getRawBytes());
        self::assertStringNotContainsString($secret, $key->saveToAsciiSafeString());
    }

    /**
     * La même clé à chaque dérivation (deux pods, un redémarrage déchiffrent
     * ce qu'un autre a chiffré), une autre sous un autre secret.
     */
    public function testTheKeyIsStableForASecretAndDiffersForAnother(): void
    {
        $key = OAuthEncryptionKey::fromSecret(TestKeys::material());
        $ciphertext = Crypto::encrypt('payload', $key);

        self::assertSame('payload', Crypto::decrypt($ciphertext, OAuthEncryptionKey::fromSecret(TestKeys::material())));

        $this->expectException(WrongKeyOrModifiedCiphertextException::class);
        Crypto::decrypt($ciphertext, OAuthEncryptionKey::fromSecret(TestKeys::material('autre')));
    }

    public function testAnEmptySecretIsRefused(): void
    {
        $this->expectException(EmptyApplicationSecretException::class);

        OAuthEncryptionKey::fromSecret('');
    }

    /**
     * Le service garde le secret pour dériver au premier usage, jamais à la
     * construction : le `cache:clear` du `docker build` construit des services
     * sans `APP_SECRET` (#474).
     */
    public function testTheServiceDerivesOnFirstUseNotAtConstruction(): void
    {
        $service = new OAuthEncryptionKey('');

        $this->expectException(EmptyApplicationSecretException::class);
        $service->key();
    }

    public function testTheServiceKeyIsTheDerivedOne(): void
    {
        $service = new OAuthEncryptionKey(TestKeys::material());

        self::assertSame(OAuthEncryptionKey::fromSecret(TestKeys::material())->getRawBytes(), $service->key()->getRawBytes());
    }
}
