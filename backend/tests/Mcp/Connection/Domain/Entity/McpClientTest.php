<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Domain\Entity;

use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\ValueObject\CimdClientId;
use App\Mcp\Connection\Domain\ValueObject\ClientMetadataDocument;
use App\Mcp\Connection\Domain\ValueObject\ClientRegistration;
use App\Mcp\Connection\Domain\ValueObject\McpClientId;
use App\Mcp\Connection\Domain\ValueObject\McpClientName;
use App\Mcp\Connection\Domain\ValueObject\RedirectUri;
use App\Tests\Mcp\Connection\Fixtures\ClaudeCodeMetadata;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Le client MCP (#647) : enregistré à partir de valeurs déjà jugées par le
 * domaine (McpClientId, McpClientName, RedirectUri), il juge une redirection
 * demandée par ses redirections enregistrées — il suffit qu'une seule
 * corresponde. Il porte son mode d'enregistrement (#651) : pré-enregistré, ou
 * déclaré par le document de métadonnées qu'il tient.
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

    /** Un client enregistré à la main (#647) est pré-enregistré : c'est le mode des lignes d'avant CIMD. */
    public function testARegisteredClientIsPreregistered(): void
    {
        self::assertSame(ClientRegistration::Preregistered, $this->client(['https://app.example/cb'])->registration());
    }

    /**
     * Un client déclaré (#651) prend son identifiant, son nom et ses
     * redirections du document jugé, et le dit : c'est ce mode qui autorise le
     * dépôt à le rafraîchir, jamais un client pré-enregistré.
     */
    public function testADiscoveredClientCarriesItsDocument(): void
    {
        $clientId = CimdClientId::tryFrom(ClaudeCodeMetadata::CLIENT_ID);
        self::assertNotNull($clientId);
        $at = new DateTimeImmutable('2026-11-13T10:00:00+01:00');

        $client = McpClient::discovered(ClientMetadataDocument::fromJson($clientId, ClaudeCodeMetadata::json()), $at);

        self::assertSame(ClientRegistration::Cimd, $client->registration());
        self::assertSame(ClaudeCodeMetadata::CLIENT_ID, $client->getClientId());
        self::assertSame('Claude Code', $client->getName());
        self::assertSame($at, $client->registeredAt());
        self::assertTrue($client->allowsRedirect('http://localhost:53124/callback'));
        self::assertFalse($client->allowsRedirect('http://localhost:53124/other'));
        self::assertFalse($client->allowsRedirect('https://evil.test/callback'));
    }

    /** Un client dont l'identifiant a la forme CIMD donne l'hôte de cet identifiant. */
    public function testACimdShapedClientIdExposesItsHost(): void
    {
        $clientId = CimdClientId::tryFrom(ClaudeCodeMetadata::CLIENT_ID);
        self::assertNotNull($clientId);
        $client = McpClient::discovered(ClientMetadataDocument::fromJson($clientId, ClaudeCodeMetadata::json()), new DateTimeImmutable());

        self::assertSame('claude.ai', $client->cimdHost());
    }

    /** Un identifiant ordinaire (client pré-enregistré) n'a pas de forme CIMD : pas d'hôte. */
    public function testAnOrdinaryClientIdHasNoCimdHost(): void
    {
        $client = McpClient::register(
            McpClientId::fromString('claude-desktop'),
            McpClientName::fromString('Client de test'),
            [RedirectUri::fromString('https://app.example/cb')],
            new DateTimeImmutable(),
        );

        self::assertNull($client->cimdHost());
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
