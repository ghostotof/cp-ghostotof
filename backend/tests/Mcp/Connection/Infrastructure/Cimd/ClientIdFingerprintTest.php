<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Infrastructure\Cimd;

use App\Mcp\Connection\Domain\ValueObject\CimdClientId;
use App\Mcp\Connection\Infrastructure\Cimd\ClientIdFingerprint;
use PHPUnit\Framework\TestCase;

/**
 * L'empreinte d'un `client_id` CIMD (#651), clé du magasin des documents et du
 * compteur des clients déjà déclarés : une seule dérivation, jugée sur la
 * valeur seule, sans conteneur. Les deux utilisateurs sont éprouvés dans
 * CachedClientMetadataStoreTest et SymfonyClientMetadataFetchQuotaTest.
 */
final class ClientIdFingerprintTest extends TestCase
{
    private const string CLIENT_A = 'https://claude.ai/oauth/claude-code-client-metadata';
    private const string CLIENT_B = 'https://claude.ai/oauth/another-client-metadata';

    /**
     * La valeur ne doit jamais changer : les documents déjà en cache sont sous
     * cette clé, et un changement les rendrait tous introuvables d'un coup. Le
     * témoin est écrit en dur, calculé hors du code testé (`sha256sum`).
     */
    public function testTheFingerprintIsTheSha256OfTheClientIdAndStaysSo(): void
    {
        self::assertSame(
            hash('sha256', self::CLIENT_A),
            ClientIdFingerprint::of($this->clientId(self::CLIENT_A)),
        );
        self::assertSame(
            '569ea71ec53b5b4e5b1bd92142355f9b504ca98c28606b8cb3d16bed1457a2f7',
            ClientIdFingerprint::of($this->clientId(self::CLIENT_A)),
        );
    }

    public function testTheFingerprintHasAFixedLengthAndAlphabet(): void
    {
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', ClientIdFingerprint::of($this->clientId(self::CLIENT_B)));
    }

    public function testTwoClientIdsHaveTwoFingerprintsAndOneClientIdAlwaysTheSame(): void
    {
        self::assertNotSame(
            ClientIdFingerprint::of($this->clientId(self::CLIENT_A)),
            ClientIdFingerprint::of($this->clientId(self::CLIENT_B)),
        );
        self::assertSame(
            ClientIdFingerprint::of($this->clientId(self::CLIENT_A)),
            ClientIdFingerprint::of($this->clientId(self::CLIENT_A)),
        );
    }

    public function testTheClientIdIsNotReadableInTheFingerprint(): void
    {
        self::assertStringNotContainsString('claude.ai', ClientIdFingerprint::of($this->clientId(self::CLIENT_A)));
    }

    private function clientId(string $clientId): CimdClientId
    {
        $id = CimdClientId::tryFrom($clientId);
        self::assertNotNull($id);

        return $id;
    }
}
