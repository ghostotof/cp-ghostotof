<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Domain\Entity;

use App\Mcp\Connection\Domain\Entity\McpClient;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Le client MCP juge une redirection demandée par ses redirections
 * enregistrées (#647) : il suffit qu'une seule corresponde.
 */
final class McpClientTest extends TestCase
{
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
     * règle plus stricte, ou à la main en base) n'autorise rien, et ne fait
     * pas échouer le jugement des autres : jamais d'exception ici, le point
     * d'autorisation répondrait un 500 au lieu d'un refus.
     */
    public function testARegisteredValueThatNoLongerParsesAllowsNothing(): void
    {
        $client = $this->client(['http://app.example/cb', 'javascript:alert(1)', 'https://app.example/ok']);

        self::assertFalse($client->allowsRedirect('http://app.example/cb'));
        self::assertFalse($client->allowsRedirect('javascript:alert(1)'));
        self::assertTrue($client->allowsRedirect('https://app.example/ok'));
    }

    /**
     * @param list<string> $redirectUris
     */
    private function client(array $redirectUris): McpClient
    {
        return McpClient::register('https://app.example/client.json', 'Client de test', $redirectUris, new DateTimeImmutable());
    }
}
