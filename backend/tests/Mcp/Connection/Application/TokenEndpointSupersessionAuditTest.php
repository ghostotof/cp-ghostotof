<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Application;

use App\Mcp\Connection\Application\CodeExchangeSupersessionInterface;
use App\Mcp\Connection\Application\GrantRefusalAuditInterface;
use App\Mcp\Connection\Application\McpConnectionRevokerInterface;
use App\Mcp\Connection\Application\OAuthEndpoints;
use App\Mcp\Connection\Application\ReplayProofInterface;
use App\Mcp\Connection\Application\TokenEndpoint;
use App\Mcp\Connection\Application\TransactionInterface;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Shared\Domain\Security\McpConnectionReference;
use App\Tests\Mcp\Connection\DrivesTheOAuthFlow;
use App\Tests\Support\ReadsAllChannelsLog;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Server\AuthorizationServer;
use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Log\LoggerInterface;
use RuntimeException;
use SensitiveParameter;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * L'audit du remplacement au point de jeton (#726, D2), sur le vrai league,
 * la vraie base et le vrai remplacement : ce que ConsentControllerTest ne
 * peut pas provoquer par HTTP — une transaction annulée **après** le
 * remplacement, une panne de l'audit **après** le COMMIT.
 *
 * TokenEndpoint est construit ici avec les services du conteneur, sauf la
 * doublure du test : la transaction (RollingBackTransaction) ou l'écrivain du
 * journal de sécurité. Chaque test part du même état : C1 échangée (ses
 * jetons), un second accord approuvé (C2), son code pas encore échangé.
 */
final class TokenEndpointSupersessionAuditTest extends WebTestCase
{
    use DrivesTheOAuthFlow;
    use ReadsAllChannelsLog;

    private const string FORM = 'application/x-www-form-urlencoded';

    private const string NOT_AUDITED = 'Superseded connection not audited.';

    /** @var list<McpConnectionReference> les connexions dont la révocation a été journalisée */
    private array $revokedAudits = [];

    protected function setUp(): void
    {
        $this->setUpOAuthFlow();
    }

    protected function tearDown(): void
    {
        $this->tearDownOAuthFlow();
        parent::tearDown();
    }

    /**
     * Le remplacement s'écrit dans la transaction de l'échange, son audit
     * **après** le COMMIT : une transaction annulée une fois le remplacement
     * fait n'a rien remplacé, et n'écrit aucune ligne `mcp-connection-revoked`.
     * C1 reste active, le code de C2 échangeable.
     *
     * **Mesuré** : la boucle d'audit déplacée dans `TokenEndpoint::issue()`
     * (dans la transaction) le fait rougir — une ligne pour une révocation
     * annulée.
     */
    public function testARolledBackExchangeAuditsNoSupersededConnection(): void
    {
        [$firstConnectionId, $secondCode] = $this->firstExchangedThenSecondGranted();
        $endpoint = $this->endpoint(
            new RollingBackTransaction(self::getContainer()->get(TransactionInterface::class)),
            $this->recordingAuditLogger(),
        );

        try {
            $endpoint->respond($this->exchangeRequest($secondCode), new Response());
            self::fail('La transaction annulée doit remonter.');
        } catch (RolledBackOnPurposeException) {
        }

        self::assertSame([], $this->revokedAudits, 'Une ligne pour un remplacement que le ROLLBACK a annulé.');
        self::assertNull($this->revocationCauseOf($firstConnectionId));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_connection WHERE revoked_at IS NOT NULL'));
    }

    /**
     * Une panne de l'audit après le COMMIT ne change pas la réponse (la règle
     * de #725, « An audit failure never changes the answer ») : le client
     * reçoit ses jetons — rendus en 500, il rejouerait son code avec son
     * vérificateur, rejeu prouvé, et C2 serait révoquée à son tour —, C1 est
     * révoquée `superseded`, et la panne sort en `critical` sur le canal
     * principal, la classe seule, sans code ni jeton.
     */
    public function testAnAuditFailureAfterTheCommitLeavesTheTokensIssued(): void
    {
        [$firstConnectionId, $secondCode] = $this->firstExchangedThenSecondGranted();
        $failing = self::createStub(SecurityAuditLoggerInterface::class);
        $failing->method('mcpConnectionSuperseded')->willThrowException(new RuntimeException('Journal de sécurité indisponible'));

        $response = $this->endpoint(self::getContainer()->get(TransactionInterface::class), $failing)
            ->respond($this->exchangeRequest($secondCode), new Response());

        self::assertSame(200, $response->getStatusCode());
        $issued = json_decode((string) $response->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($issued);
        $accessToken = $issued['access_token'] ?? null;
        $refreshToken = $issued['refresh_token'] ?? null;
        self::assertIsString($accessToken);
        self::assertIsString($refreshToken);
        self::assertSame('superseded', $this->revocationCauseOf($firstConnectionId));

        $failures = array_values(array_filter(
            self::allChannelsLogRecordsOf('app'),
            static fn(LogRecord $record): bool => self::NOT_AUDITED === $record->message,
        ));
        self::assertCount(1, $failures);
        self::assertSame(Level::Critical, $failures[0]->level);
        self::assertSame(['exceptionClass' => RuntimeException::class], $failures[0]->context);
        // Chaque enregistrement de chaque canal, tel que le formateur de la
        // production l'écrit.
        $formatter = new JsonFormatter();
        $logged = implode('', array_map($formatter->format(...), self::allChannelsLogRecords()));
        // Des messages qui ne citent pas la valeur : un échec la publierait
        // dans le journal de la CI.
        foreach ([$secondCode, $accessToken, $refreshToken] as $secret) {
            self::assertFalse(str_contains($logged, $secret), 'Un journal cite le code ou un jeton émis.');
        }
    }

    /**
     * C1 échangée par HTTP, puis un second accord du même compte au même
     * client : l'identifiant de C1 et le code de C2, pas encore échangé.
     *
     * @return array{string, string}
     */
    private function firstExchangedThenSecondGranted(): array
    {
        $issued = $this->exchange($this->grantedCode());
        self::assertSame(200, $issued->getStatusCode(), substr((string) $issued->getContent(), 0, 500));
        $firstConnectionId = $this->mcpConnections()->fetchOne('SELECT id FROM mcp_connection');
        self::assertIsString($firstConnectionId);
        $secondCode = $this->codeGrantedByTheSignedInAccount();
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_connection WHERE revoked_at IS NOT NULL'));

        return [$firstConnectionId, $secondCode];
    }

    /**
     * Le point de jeton tel que le conteneur le construit, sauf la
     * transaction et l'écrivain du journal de sécurité.
     */
    private function endpoint(TransactionInterface $transaction, SecurityAuditLoggerInterface $auditLogger): TokenEndpoint
    {
        $container = self::getContainer();

        return new TokenEndpoint(
            $container->get(AuthorizationServer::class),
            $container->get(OAuthEndpoints::class),
            $container->get(McpClientRepositoryInterface::class),
            $transaction,
            $container->get(McpConnectionRevokerInterface::class),
            $container->get(GrantRefusalAuditInterface::class),
            $container->get(ReplayProofInterface::class),
            $container->get(CodeExchangeSupersessionInterface::class),
            $auditLogger,
            $container->get(LoggerInterface::class),
        );
    }

    /**
     * Un écrivain du journal de sécurité qui consigne les remplacements
     * journalisés (`mcpConnectionSuperseded()`, #726 B3 : la connexion
     * remplacée et celle qui la remplace).
     */
    private function recordingAuditLogger(): SecurityAuditLoggerInterface
    {
        $auditLogger = self::createStub(SecurityAuditLoggerInterface::class);
        $auditLogger->method('mcpConnectionSuperseded')->willReturnCallback(function (McpConnectionReference $connection): void {
            $this->revokedAudits[] = $connection;
        });

        return $auditLogger;
    }

    /** L'échange du code, en PSR-7, comme le contrôleur le présente. */
    private function exchangeRequest(#[SensitiveParameter] string $code): ServerRequest
    {
        return new ServerRequest('POST', 'http://localhost/api/oauth/token', ['Content-Type' => self::FORM], self::formBody($this->exchangeParameters($code)));
    }

    private function revocationCauseOf(string $connectionId): ?string
    {
        $cause = $this->mcpConnections()->fetchOne('SELECT revocation_cause FROM mcp_connection WHERE id = :id', ['id' => $connectionId]);
        self::assertTrue(null === $cause || \is_string($cause));

        return $cause;
    }

    private function mcpConnections(): Connection
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }
}
