<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Application;

use App\Mcp\Connection\Application\AuthorizationRequestSummary;
use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\ValueObject\CimdClientId;
use App\Mcp\Connection\Domain\ValueObject\ClientMetadataDocument;
use App\Mcp\Connection\Domain\ValueObject\ClientRegistration;
use App\Mcp\Connection\Infrastructure\League\OAuthClient;
use App\Tests\Mcp\Connection\Fixtures\ClaudeCodeMetadata;
use App\Tests\Mcp\Connection\RegisteredMcpClient;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Le résumé se tire du client que league a jugé (`getClient()` de la demande),
 * jamais du seul client déclaré qu'on lui joint : l'origine de l'identité
 * (registration()) et le domaine affiché (verifiedDomain()) suivent le client
 * jugé ; le client déclaré ne sert que de canal vers la persistance, et
 * seulement s'il est celui que league a jugé.
 */
final class AuthorizationRequestSummaryTest extends TestCase
{
    private const string PREREGISTERED_ID = 'https://client.example.test/metadata.json';

    public function testAJudgedCimdClientIsSummarisedAsCimdWithItsDomain(): void
    {
        $declared = $this->declaredClient();

        $summary = $this->summary(new OAuthClient($declared), $declared);

        self::assertSame(ClientRegistration::Cimd, $summary->registration());
        self::assertSame('claude.ai', $summary->verifiedDomain());
        self::assertSame($declared, $summary->judgedDiscoveredClient());
    }

    /**
     * Le client jugé est la source : même sans canal `discoveredClient`, un
     * client jugé `cimd` (ligne en base) se résume comme tel.
     */
    public function testAJudgedCimdClientIsSummarisedAsCimdWithoutTheDiscoveredChannel(): void
    {
        $summary = $this->summary(new OAuthClient($this->declaredClient()), null);

        self::assertSame(ClientRegistration::Cimd, $summary->registration());
        self::assertSame('claude.ai', $summary->verifiedDomain());
        self::assertNull($summary->judgedDiscoveredClient());
    }

    public function testAJudgedPreregisteredClientIsSummarisedWithoutDomain(): void
    {
        $summary = $this->summary($this->preregistered(), null);

        self::assertSame(ClientRegistration::Preregistered, $summary->registration());
        self::assertNull($summary->verifiedDomain());
    }

    /**
     * Un client déclaré d'un autre `client_id` que celui de la demande jugée
     * ne colore ni l'origine de l'identité, ni le domaine, et n'est pas rendu
     * à la persistance.
     */
    public function testADiscoveredClientOfAnotherClientIdNeverColoursTheSummary(): void
    {
        $summary = $this->summary($this->preregistered(), $this->declaredClient());

        self::assertSame(ClientRegistration::Preregistered, $summary->registration());
        self::assertNull($summary->verifiedDomain());
        self::assertNull($summary->judgedDiscoveredClient());
    }

    /**
     * Même `client_id` que le client déclaré, mais le client jugé par league
     * est une ligne pré-enregistrée : le client déclaré n'est PAS celui qui a
     * été jugé (identité d'objet, pas d'identifiant) — il n'est pas rendu à la
     * persistance et ne colore pas le résumé.
     */
    public function testAJudgedPreregisteredClientOfTheSameClientIdNeverReturnsTheDiscoveredClient(): void
    {
        $judged = new OAuthClient(RegisteredMcpClient::of(ClaudeCodeMetadata::CLIENT_ID, 'Client de test', ['http://localhost/callback']));

        $summary = $this->summary($judged, $this->declaredClient());

        self::assertSame(ClientRegistration::Preregistered, $summary->registration());
        self::assertNull($summary->verifiedDomain());
        self::assertNull($summary->judgedDiscoveredClient());
    }

    private function summary(OAuthClient $judged, ?McpClient $discovered): AuthorizationRequestSummary
    {
        $request = new AuthorizationRequest();
        $request->setClient($judged);

        return new AuthorizationRequestSummary(
            clientId: $judged->getIdentifier(),
            clientName: $judged->getName(),
            redirectUri: 'http://localhost/callback',
            redirectHost: 'localhost',
            scope: 'nominative:read',
            resource: 'https://api.example.test/api/mcp',
            authorizationRequest: $request,
            discoveredClient: $discovered,
        );
    }

    private function preregistered(): OAuthClient
    {
        return new OAuthClient(RegisteredMcpClient::of(self::PREREGISTERED_ID, 'Client de test', ['http://localhost/callback']));
    }

    private function declaredClient(): McpClient
    {
        $clientId = CimdClientId::tryFrom(ClaudeCodeMetadata::CLIENT_ID) ?? throw new LogicException('Identifiant de test illisible.');

        return McpClient::discovered(
            ClientMetadataDocument::fromJson($clientId, ClaudeCodeMetadata::json()),
            new MockClock('2026-11-01 10:00:00')->now(),
        );
    }
}
