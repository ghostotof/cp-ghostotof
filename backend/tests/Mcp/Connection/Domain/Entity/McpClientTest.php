<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Domain\Entity;

use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\ValueObject\McpClientId;
use App\Mcp\Connection\Domain\ValueObject\McpClientName;
use App\Mcp\Connection\Domain\ValueObject\RedirectUri;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Le client MCP (#647) : enregistré à partir de valeurs déjà jugées par le
 * domaine (McpClientId, McpClientName, RedirectUri), il juge une redirection
 * demandée par ses redirections enregistrées — il suffit qu'une seule
 * corresponde.
 */
final class McpClientTest extends TestCase
{
    public function testARegisteredClientKeepsItsValidatedValues(): void
    {
        $at = new DateTimeImmutable('2026-10-24T10:00:00+02:00');

        $client = McpClient::register(
            McpClientId::fromString('https://app.example/client.json'),
            McpClientName::fromString(" Client de test\u{00A0}"),
            [RedirectUri::fromString('https://app.example/cb')],
            $at,
        );

        self::assertSame('https://app.example/client.json', $client->getClientId());
        self::assertSame('Client de test', $client->getName());
        self::assertSame(['https://app.example/cb'], $client->redirectUris());
        self::assertSame($at, $client->registeredAt());
    }

    /** Une redirection répétée à l'identique n'est enregistrée qu'une fois, dans l'ordre reçu. */
    public function testARepeatedRedirectIsKeptOnce(): void
    {
        $client = $this->client(['http://localhost/callback', 'https://app.example/cb', 'http://localhost/callback']);

        self::assertSame(['http://localhost/callback', 'https://app.example/cb'], $client->redirectUris());
    }

    public function testARedirectMatchingOneOfTheRegisteredOnesIsAllowed(): void
    {
        $client = $this->client(['https://app.example/cb', 'http://127.0.0.1/callback']);

        self::assertTrue($client->allowsRedirect('https://app.example/cb'));
        self::assertTrue($client->allowsRedirect('http://127.0.0.1:53412/callback'));
    }

    public function testARedirectMatchingNoneOfTheRegisteredOnesIsRefused(): void
    {
        $client = $this->client(['https://app.example/cb', 'http://127.0.0.1/callback']);

        self::assertFalse($client->allowsRedirect('https://evil.example/cb'));
        self::assertFalse($client->allowsRedirect('http://localhost:53412/callback'));
        self::assertFalse($client->allowsRedirect('not a uri'));
    }

    /**
     * Une valeur enregistrée qui ne passe plus RedirectUri (écrite avant une
     * règle plus stricte, ou à la main en base — d'où la réflexion : register()
     * ne l'accepterait pas) n'autorise rien, et ne fait pas échouer le jugement
     * des autres : jamais d'exception ici, le point d'autorisation répondrait
     * un 500 au lieu d'un refus.
     */
    public function testARegisteredValueThatNoLongerParsesAllowsNothing(): void
    {
        $client = $this->client(['https://app.example/ok']);
        new ReflectionProperty(McpClient::class, 'redirectUris')->setValue($client, ['http://app.example/cb', 'javascript:alert(1)', 'https://app.example/ok']);

        self::assertFalse($client->allowsRedirect('http://app.example/cb'));
        self::assertFalse($client->allowsRedirect('javascript:alert(1)'));
        self::assertTrue($client->allowsRedirect('https://app.example/ok'));
    }

    /**
     * @param list<string> $redirectUris
     */
    private function client(array $redirectUris): McpClient
    {
        return McpClient::register(
            McpClientId::fromString('https://app.example/client.json'),
            McpClientName::fromString('Client de test'),
            array_map(RedirectUri::fromString(...), $redirectUris),
            new DateTimeImmutable(),
        );
    }
}
