<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Domain\Entity;

use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Exception\InvalidMcpClientRedirectsException;
use App\Mcp\Connection\Domain\Exception\McpClientDeclaredByDocumentException;
use App\Mcp\Connection\Domain\ValueObject\CimdClientId;
use App\Mcp\Connection\Domain\ValueObject\ClientMetadataDocument;
use App\Mcp\Connection\Domain\ValueObject\ClientRegistration;
use App\Mcp\Connection\Domain\ValueObject\McpClientId;
use App\Mcp\Connection\Domain\ValueObject\McpClientName;
use App\Mcp\Connection\Domain\ValueObject\RedirectUri;
use App\Tests\Mcp\Connection\Fixtures\ClaudeCodeMetadata;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
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

    /**
     * Un client découvert respecte l'invariant des redirections, comme un
     * client pré-enregistré (#652) : le document les borne déjà à la lecture,
     * mais l'entité ne s'en remet pas à lui. Le constructeur du document étant
     * privé, un document hors bornes se construit par réflexion — l'état que
     * l'entité refuse d'enregistrer, sans qu'aucune porte publique ne le fabrique.
     */
    public function testADiscoveredClientWithoutRedirectIsRefused(): void
    {
        $this->expectException(InvalidMcpClientRedirectsException::class);
        $this->expectExceptionMessage(InvalidMcpClientRedirectsException::none()->getMessage());

        McpClient::discovered($this->documentWithRedirects([]), new DateTimeImmutable());
    }

    public function testADiscoveredClientWithElevenDistinctRedirectsIsRefused(): void
    {
        $this->expectException(InvalidMcpClientRedirectsException::class);
        $this->expectExceptionMessage(InvalidMcpClientRedirectsException::tooMany()->getMessage());

        McpClient::discovered(
            $this->documentWithRedirects(array_map(RedirectUri::fromString(...), $this->distinctRedirects(McpClient::MAX_REDIRECT_URIS + 1))),
            new DateTimeImmutable(),
        );
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

    /** Un client sans redirection ne pourrait jamais passer le consentement (#652) : refusé à l'enregistrement. */
    public function testRegisteringWithoutRedirectIsRefused(): void
    {
        $this->expectException(InvalidMcpClientRedirectsException::class);

        $this->client([]);
    }

    /** Au-delà de dix redirections distinctes (la borne d'un document CIMD), le client est refusé (#652). */
    public function testRegisteringElevenDistinctRedirectsIsRefused(): void
    {
        $this->expectException(InvalidMcpClientRedirectsException::class);

        $this->client($this->distinctRedirects(McpClient::MAX_REDIRECT_URIS + 1));
    }

    /** La borne est incluse : dix redirections distinctes sont admises. */
    public function testTenDistinctRedirectsAreAccepted(): void
    {
        $redirects = $this->distinctRedirects(McpClient::MAX_REDIRECT_URIS);

        self::assertSame($redirects, $this->client($redirects)->redirectUris());
    }

    /** La borne s'applique après déduplication : dix distinctes plus un doublon font dix redirections, pas onze. */
    public function testElevenRedirectsWithOneDuplicateAreAcceptedAfterDeduplication(): void
    {
        $redirects = $this->distinctRedirects(McpClient::MAX_REDIRECT_URIS);

        $client = $this->client([...$redirects, $redirects[0]]);

        self::assertSame($redirects, $client->redirectUris());
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
     * Réviser un client pré-enregistré (#652) remplace son nom et ses
     * redirections ; une redirection répétée n'est gardée qu'une fois, dans
     * l'ordre reçu, comme à l'enregistrement.
     */
    public function testRevisingReplacesNameAndRedirects(): void
    {
        $client = $this->client(['https://app.example/cb']);

        $client->revise(
            McpClientName::fromString(" Nouveau nom\u{00A0}"),
            [
                RedirectUri::fromString('https://app.example/new'),
                RedirectUri::fromString('https://app.example/other'),
                RedirectUri::fromString('https://app.example/new'),
            ],
        );

        self::assertSame('Nouveau nom', $client->getName());
        self::assertSame(['https://app.example/new', 'https://app.example/other'], $client->redirectUris());
        self::assertFalse($client->allowsRedirect('https://app.example/cb'));
    }

    /** L'identifiant public est immuable, et la révision n'est pas un nouvel enregistrement. */
    public function testRevisingKeepsTheClientIdAndTheRegistrationDate(): void
    {
        $at = new DateTimeImmutable('2026-10-24T10:00:00+02:00');
        $client = McpClient::register(
            McpClientId::fromString('https://app.example/client.json'),
            McpClientName::fromString('Client de test'),
            [RedirectUri::fromString('https://app.example/cb')],
            $at,
        );
        $id = $client->getId();

        $client->revise(McpClientName::fromString('Autre nom'), [RedirectUri::fromString('https://app.example/new')]);

        self::assertSame('https://app.example/client.json', $client->getClientId());
        self::assertSame($at, $client->registeredAt());
        self::assertSame($id, $client->getId());
        self::assertSame(ClientRegistration::Preregistered, $client->registration());
    }

    public function testRevisingToNoRedirectIsRefused(): void
    {
        $client = $this->client(['https://app.example/cb']);

        try {
            $client->revise(McpClientName::fromString('Autre nom'), []);
            self::fail('Une révision sans redirection devait être refusée.');
        } catch (InvalidMcpClientRedirectsException $exception) {
            self::assertSame(InvalidMcpClientRedirectsException::none()->getMessage(), $exception->getMessage());
        }

        // Refus atomique : ni le nom ni les redirections n'ont bougé.
        self::assertSame('Client de test', $client->getName());
        self::assertSame(['https://app.example/cb'], $client->redirectUris());
    }

    public function testRevisingToElevenDistinctRedirectsIsRefused(): void
    {
        $client = $this->client(['https://app.example/cb']);

        try {
            $client->revise(
                McpClientName::fromString('Autre nom'),
                array_map(RedirectUri::fromString(...), $this->distinctRedirects(McpClient::MAX_REDIRECT_URIS + 1)),
            );
            self::fail('Une révision au-delà de la borne devait être refusée.');
        } catch (InvalidMcpClientRedirectsException $exception) {
            self::assertSame(InvalidMcpClientRedirectsException::tooMany()->getMessage(), $exception->getMessage());
        }

        self::assertSame('Client de test', $client->getName());
        self::assertSame(['https://app.example/cb'], $client->redirectUris());
    }

    /**
     * Le document d'un client CIMD fait foi (#651) : le réviser à la main
     * serait écrasé au prochain rafraîchissement, et ferait mentir la page de
     * consentement. Refus, sans rien changer.
     */
    public function testRevisingACimdClientIsRefused(): void
    {
        $clientId = CimdClientId::tryFrom(ClaudeCodeMetadata::CLIENT_ID);
        self::assertNotNull($clientId);
        $client = McpClient::discovered(ClientMetadataDocument::fromJson($clientId, ClaudeCodeMetadata::json()), new DateTimeImmutable());
        $redirects = $client->redirectUris();

        try {
            $client->revise(McpClientName::fromString('Autre nom'), [RedirectUri::fromString('https://app.example/new')]);
            self::fail('Réviser un client CIMD devait être refusé.');
        } catch (McpClientDeclaredByDocumentException $exception) {
            self::assertSame('/errors/client-declared-by-document', $exception->getType());
            self::assertSame(409, $exception->getStatus());
        }

        self::assertSame('Claude Code', $client->getName());
        self::assertSame($redirects, $client->redirectUris());
    }

    /**
     * @return list<string> $count redirections https valides, deux à deux distinctes
     */
    private function distinctRedirects(int $count): array
    {
        return array_map(static fn (int $i): string => \sprintf('https://app.example/cb/%d', $i), range(1, $count));
    }

    /**
     * Un document dont les redirections sont celles données, hors de ce que
     * `fromJson()` admet : son constructeur est privé, on l'appelle par réflexion.
     *
     * @param list<RedirectUri> $redirectUris
     */
    private function documentWithRedirects(array $redirectUris): ClientMetadataDocument
    {
        $clientId = CimdClientId::tryFrom(ClaudeCodeMetadata::CLIENT_ID);
        self::assertNotNull($clientId);

        $class = new ReflectionClass(ClientMetadataDocument::class);
        $document = $class->newInstanceWithoutConstructor();
        $class->getConstructor()?->invoke($document, $clientId, McpClientName::fromString('Client de test'), $redirectUris);

        return $document;
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
