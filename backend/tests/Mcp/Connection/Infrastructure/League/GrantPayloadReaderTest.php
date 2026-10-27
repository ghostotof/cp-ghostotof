<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Infrastructure\League;

use App\Mcp\Connection\Infrastructure\League\GrantPayloadReader;
use App\Mcp\Connection\Infrastructure\League\OAuthEncryptionKey;
use App\Tests\Support\TestKeys;
use Defuse\Crypto\Crypto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Ce qu'un code d'autorisation ou un jeton de rafraîchissement remis par
 * league porte (#647, #725) : le serveur seul sait le déchiffrer, donc un
 * artefact qu'il déchiffre est authentique et désigne la connexion de son
 * `user_id` (ConnectionAsUser). Les charges sont chiffrées ici comme league
 * les chiffre (`CryptTrait::encrypt()`, defuse sous la clé dérivée).
 */
final class GrantPayloadReaderTest extends TestCase
{
    private const string CONNECTION = '0199f0a4-5c3e-7a2b-8d1e-2f3a4b5c6d7e';

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function authenticPayloads(): iterable
    {
        yield 'code' => [['client_id' => 'client', 'auth_code_id' => 'abc', 'user_id' => self::CONNECTION, 'expire_time' => 1, 'scopes' => []]];
        yield 'rafraîchissement' => [['client_id' => 'client', 'refresh_token_id' => 'def', 'access_token_id' => 'ghi', 'user_id' => self::CONNECTION, 'expire_time' => 1, 'scopes' => []]];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('authenticPayloads')]
    public function testAnAuthenticGrantNamesItsConnection(array $payload): void
    {
        $connection = $this->reader()->connectionOf($this->encrypted($payload));

        self::assertNotNull($connection);
        self::assertTrue(Uuid::fromString(self::CONNECTION)->equals($connection));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function grantsNamingNoConnection(): iterable
    {
        yield 'texte quelconque' => ['pas-un-artefact'];
        yield 'vide' => [''];
    }

    #[DataProvider('grantsNamingNoConnection')]
    public function testAnUnreadableGrantNamesNoConnection(string $grant): void
    {
        self::assertNull($this->reader()->connectionOf($grant));
    }

    public function testAGrantEncryptedUnderAnotherKeyNamesNoConnection(): void
    {
        $grant = Crypto::encrypt(json_encode(['user_id' => self::CONNECTION], \JSON_THROW_ON_ERROR), OAuthEncryptionKey::fromSecret(TestKeys::material('autre')));

        self::assertNull($this->reader()->connectionOf($grant));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function payloadsWithoutAConnection(): iterable
    {
        yield 'sans user_id' => [['auth_code_id' => 'abc']];
        yield 'user_id hors UUID' => [['user_id' => 'jane']];
        yield 'user_id entier' => [['user_id' => 42]];
        yield 'user_id UUID en majuscules' => [['user_id' => strtoupper(self::CONNECTION)]];
        yield 'liste' => [[self::CONNECTION]];
        yield 'chaîne' => [self::CONNECTION];
    }

    /**
     * Seul un UUID RFC 4122, tel que ConnectionAsUser l'écrit, désigne une
     * connexion : toute autre charge déchiffrée n'en désigne aucune.
     */
    #[DataProvider('payloadsWithoutAConnection')]
    public function testAPayloadWithoutAConnectionUuidNamesNone(mixed $payload): void
    {
        self::assertNull($this->reader()->connectionOf($this->encrypted($payload)));
    }

    public function testARefreshTokenIdentifierIsRead(): void
    {
        $token = $this->encrypted(['refresh_token_id' => 'def', 'user_id' => self::CONNECTION]);

        self::assertSame('def', $this->reader()->refreshTokenIdentifierOf($token));
        self::assertNull($this->reader()->refreshTokenIdentifierOf($this->encrypted(['auth_code_id' => 'abc'])));
        self::assertNull($this->reader()->refreshTokenIdentifierOf('pas-un-jeton'));
    }

    private function reader(): GrantPayloadReader
    {
        return new GrantPayloadReader(new OAuthEncryptionKey(TestKeys::material()));
    }

    private function encrypted(mixed $payload): string
    {
        return Crypto::encrypt(json_encode($payload, \JSON_THROW_ON_ERROR), OAuthEncryptionKey::fromSecret(TestKeys::material()));
    }
}
