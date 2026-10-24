<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Domain\Entity;

use App\Mcp\Connection\Domain\Entity\McpAccessToken;
use App\Mcp\Connection\Domain\Entity\McpAuthorizationCode;
use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\Entity\McpRefreshToken;
use App\Mcp\Connection\Domain\Exception\MalformedTokenHashException;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Mcp\Connection\RegisteredMcpClient;
use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Les trois entités qui stockent une empreinte (code, jeton d'accès, jeton de
 * rafraîchissement) n'acceptent que la forme que rend `hash('sha256', …)`, et
 * leur révocation garde son premier instant.
 */
final class McpTokenEntitiesTest extends TestCase
{
    /**
     * Les trois constructeurs, chacun réduit à « construire avec cette empreinte ».
     *
     * @return array<string, array{Closure(McpConnection, string): (McpAuthorizationCode|McpAccessToken|McpRefreshToken)}>
     */
    public static function constructors(): array
    {
        return [
            'code' => [static fn (McpConnection $connection, string $hash): McpAuthorizationCode => new McpAuthorizationCode($connection, $hash, new DateTimeImmutable('+10 minutes'))],
            "jeton d'accès" => [static fn (McpConnection $connection, string $hash): McpAccessToken => new McpAccessToken($connection, $hash, 'https://example.test/api/mcp', new DateTimeImmutable('+1 hour'))],
            'rafraîchissement' => [static fn (McpConnection $connection, string $hash): McpRefreshToken => new McpRefreshToken($connection, $hash, null, new DateTimeImmutable('+30 days'))],
        ];
    }

    /**
     * Chaque forme refusée, pour chaque entité. L'identifiant en clair de 80
     * caractères hexadécimaux est la forme que league donne aux siens : le
     * stocker tel quel est l'erreur que la garde existe pour arrêter.
     *
     * @return iterable<string, array{Closure(McpConnection, string): (McpAuthorizationCode|McpAccessToken|McpRefreshToken), string}>
     */
    public static function malformedHashes(): iterable
    {
        $malformed = [
            'identifiant en clair (80 hex)' => bin2hex(random_bytes(40)),
            'hexadécimal majuscule' => strtoupper(hash('sha256', 'clear-token')),
            '63 caractères' => substr(hash('sha256', 'clear-token'), 0, 63),
            'saut de ligne final' => hash('sha256', 'clear-token')."\n",
        ];

        foreach (self::constructors() as $entity => [$construct]) {
            foreach ($malformed as $shape => $hash) {
                yield $entity.', '.$shape => [$construct, $hash];
            }
        }
    }

    /**
     * @param Closure(McpConnection, string): (McpAuthorizationCode|McpAccessToken|McpRefreshToken) $construct
     */
    #[DataProvider('malformedHashes')]
    public function testAMalformedHashIsRefused(Closure $construct, string $hash): void
    {
        try {
            $construct(self::connection(), $hash);
            self::fail("Une empreinte mal formée a été acceptée.");
        } catch (MalformedTokenHashException $refusal) {
            self::assertStringNotContainsString(trim($hash), $refusal->getMessage(), 'Le refus cite la valeur reçue.');
        }
    }

    /**
     * @param Closure(McpConnection, string): (McpAuthorizationCode|McpAccessToken|McpRefreshToken) $construct
     */
    #[DataProvider('constructors')]
    public function testASha256HexDigestIsAccepted(Closure $construct): void
    {
        $hash = hash('sha256', 'clear-token');

        $token = $construct(self::connection(), $hash);

        self::assertSame($hash, $token instanceof McpAuthorizationCode ? $token->codeHash() : $token->tokenHash());
    }

    /**
     * Une seconde révocation, plus tardive, ne déplace pas la première :
     * l'instant dit quand le jeton est mort.
     *
     * @param Closure(McpConnection, string): (McpAuthorizationCode|McpAccessToken|McpRefreshToken) $construct
     */
    #[DataProvider('constructors')]
    public function testASecondRevocationKeepsTheFirstInstant(Closure $construct): void
    {
        $token = $construct(self::connection(), hash('sha256', 'clear-token'));
        $first = new DateTimeImmutable('2026-10-23T10:00:00+02:00');

        self::assertFalse($token->isRevoked());
        $token->revoke($first);
        $token->revoke(new DateTimeImmutable('2026-10-23T11:00:00+02:00'));

        self::assertTrue($token->isRevoked());
        self::assertSame($first, $token->revokedAt());
    }

    private static function connection(): McpConnection
    {
        return McpConnection::grant(
            new CpgUser('jane', 'hashed-password'),
            RegisteredMcpClient::of('https://client.example.test/metadata.json', 'Client de test', []),
            'nominative:read',
            new DateTimeImmutable(),
        );
    }
}
