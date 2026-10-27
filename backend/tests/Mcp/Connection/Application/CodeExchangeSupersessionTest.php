<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Application;

use App\Mcp\Connection\Application\CodeExchangeSupersession;
use App\Mcp\Connection\Application\ExchangedCodeConnectionInterface;
use App\Mcp\Connection\Application\FailSafeAudit;
use App\Mcp\Connection\Application\Supersession;
use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\ConnectionReference;
use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use App\Mcp\Connection\Domain\ValueObject\RevokedConnection;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Shared\Domain\Security\McpConnectionReference;
use App\Tests\Mcp\Connection\RegisteredMcpClient;
use DateTimeImmutable;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * CodeExchangeSupersession (#726, S3 et S6) sur un port et un dépôt simulés :
 * la décision du remplacement — seul l'échange d'un code remplace, la
 * connexion du code est gardée, les autres du couple révoquées `superseded` à
 * l'instant de l'horloge — puis son audit après le COMMIT, qui ne lève
 * jamais (#725).
 *
 * Le comportement de bout en bout (league, la base, le verrou, l'ordre des
 * ids) est celui de ConsentControllerTest, TokenEndpointConcurrencyTest et
 * TokenEndpointSupersessionAuditTest ; ici, la décision seule, sans base.
 */
final class CodeExchangeSupersessionTest extends TestCase
{
    private const string NOW = '2026-10-25T02:30:00+01:00';
    private const string ACCOUNT_ID = '01929a4e-1111-7000-8000-000000000001';
    private const string CLIENT_ID = 'https://client.example.test/metadata.json';

    private TestHandler $mainChannel;

    protected function setUp(): void
    {
        $this->mainChannel = new TestHandler();
    }

    /**
     * Tout corps qui n'est pas un échange de code : un rafraîchissement, un
     * grant inconnu, un grant absent ou reçu en tableau. Rien n'est lu ni
     * révoqué — un rafraîchissement ne prouve pas que le client a reçu une
     * nouvelle connexion.
     *
     * @return iterable<string, array{array<string, mixed>|null}>
     */
    public static function bodiesOtherThanACodeExchange(): iterable
    {
        yield 'rafraîchissement' => [['grant_type' => 'refresh_token', 'code' => 'un-code']];
        yield 'grant inconnu' => [['grant_type' => 'client_credentials', 'code' => 'un-code']];
        yield 'grant en majuscules' => [['grant_type' => 'AUTHORIZATION_CODE', 'code' => 'un-code']];
        yield 'grant absent' => [['code' => 'un-code']];
        yield 'grant en tableau' => [['grant_type' => ['authorization_code'], 'code' => 'un-code']];
        yield 'corps non analysé' => [null];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    #[DataProvider('bodiesOtherThanACodeExchange')]
    public function testAnythingButACodeExchangeSupersedesNothing(?array $body): void
    {
        $exchangedCode = $this->createMock(ExchangedCodeConnectionInterface::class);
        $exchangedCode->expects(self::never())->method('connectionIdOf');
        $connections = $this->createMock(McpConnectionRepositoryInterface::class);
        $connections->expects(self::never())->method('findOneById');
        $connections->expects(self::never())->method('revokeOtherActiveOf');
        $request = new ServerRequest('POST', '/api/oauth/token');

        $supersession = $this->supersession($exchangedCode, $connections)
            ->supersedeOthersOf(null === $body ? $request : $request->withParsedBody($body));

        self::assertNull($supersession);
    }

    /** Un code que le port ne sait pas lire (illisible, sans connexion) : rien n'est cherché ni révoqué. */
    public function testACodeDesignatingNoConnectionSupersedesNothing(): void
    {
        $exchangedCode = self::createStub(ExchangedCodeConnectionInterface::class);
        $exchangedCode->method('connectionIdOf')->willReturn(null);
        $connections = $this->createMock(McpConnectionRepositoryInterface::class);
        $connections->expects(self::never())->method('findOneById');
        $connections->expects(self::never())->method('revokeOtherActiveOf');

        self::assertNull($this->supersession($exchangedCode, $connections)->supersedeOthersOf($this->codeExchange()));
    }

    /** Une connexion que la base ne connaît pas (purgée entre-temps) : rien n'est révoqué. */
    public function testAnUnknownConnectionSupersedesNothing(): void
    {
        $connectionId = Uuid::v7();
        $exchangedCode = self::createStub(ExchangedCodeConnectionInterface::class);
        $exchangedCode->method('connectionIdOf')->willReturn($connectionId);
        $connections = $this->createMock(McpConnectionRepositoryInterface::class);
        $connections->expects(self::once())
            ->method('findOneById')
            ->with(self::callback(static fn (Uuid $id): bool => $id->equals($connectionId)))
            ->willReturn(null);
        $connections->expects(self::never())->method('revokeOtherActiveOf');

        self::assertNull($this->supersession($exchangedCode, $connections)->supersedeOthersOf($this->codeExchange()));
    }

    /**
     * Le cas nominal : la connexion du code est gardée — l'instance que le
     * dépôt rend, celle que league a verrouillée —, les autres de son couple
     * révoquées par le dépôt, cause `superseded`, à l'instant de l'horloge ;
     * le remplacement rendu nomme la connexion gardée et porte la liste du
     * dépôt telle quelle. Rien n'est audité dans ce temps-là.
     */
    public function testTheCodesConnectionIsKeptAndTheOthersOfItsPairRevoked(): void
    {
        $kept = $this->connection();
        $revoked = [$this->revoked(Uuid::v7()), $this->revoked(Uuid::v7())];
        $exchangedCode = self::createStub(ExchangedCodeConnectionInterface::class);
        $exchangedCode->method('connectionIdOf')->willReturn($kept->getId());
        $connections = $this->createMock(McpConnectionRepositoryInterface::class);
        $keptId = $kept->getId();
        $connections->expects(self::once())
            ->method('findOneById')
            ->with(self::callback(static fn (Uuid $id): bool => $id->equals($keptId)))
            ->willReturn($kept);
        $connections->expects(self::once())
            ->method('revokeOtherActiveOf')
            ->with(
                self::identicalTo($kept),
                RevocationCause::Superseded,
                self::callback(static fn (DateTimeImmutable $at): bool => new DateTimeImmutable(self::NOW)->getTimestamp() === $at->getTimestamp()),
            )
            ->willReturn($revoked);
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('mcpConnectionSuperseded');

        $supersession = $this->supersession($exchangedCode, $connections, $auditLogger)->supersedeOthersOf($this->codeExchange());

        self::assertNotNull($supersession);
        self::assertTrue($kept->getId()->equals($supersession->kept));
        self::assertSame($revoked, $supersession->revoked);
    }

    /** Un couple sans autre connexion active : un remplacement vide, pas `null`. */
    public function testAPairWithoutAnotherActiveConnectionGivesAnEmptySupersession(): void
    {
        $kept = $this->connection();
        $exchangedCode = self::createStub(ExchangedCodeConnectionInterface::class);
        $exchangedCode->method('connectionIdOf')->willReturn($kept->getId());
        $connections = self::createStub(McpConnectionRepositoryInterface::class);
        $connections->method('findOneById')->willReturn($kept);
        $connections->method('revokeOtherActiveOf')->willReturn([]);

        $supersession = $this->supersession($exchangedCode, $connections)->supersedeOthersOf($this->codeExchange());

        self::assertNotNull($supersession);
        self::assertSame([], $supersession->revoked);
    }

    /**
     * Après le COMMIT : une ligne par connexion remplacée, chacune nommant la
     * connexion gardée (`supersededBy`, #726 B3), rien sur le canal principal.
     */
    public function testEachSupersededConnectionIsAuditedNamingTheKeptOne(): void
    {
        $kept = Uuid::v7();
        $revoked = [$this->revoked(Uuid::v7()), $this->revoked(Uuid::v7())];
        $audited = [];
        $auditLogger = self::createStub(SecurityAuditLoggerInterface::class);
        $auditLogger->method('mcpConnectionSuperseded')->willReturnCallback(static function (McpConnectionReference $connection, Uuid $supersededBy) use (&$audited): void {
            $audited[] = [$connection, $supersededBy];
        });

        $this->supersession(auditLogger: $auditLogger)->auditSuperseded(new Supersession($kept, $revoked));

        self::assertCount(2, $audited);
        foreach ($revoked as $index => $connection) {
            self::assertSame($connection, $audited[$index][0]);
            self::assertTrue($kept->equals($audited[$index][1]));
        }

        self::assertSame([], $this->mainChannel->getRecords());
    }

    /**
     * Une panne de l'écrivain ne remonte pas et ne tait pas la ligne suivante
     * (#725, #726) : un `critical` constant, la classe seule, jamais le
     * message de la panne.
     */
    public function testAnAuditFailureNeitherEscapesNorSilencesTheNextLine(): void
    {
        $revoked = [$this->revoked(Uuid::v7()), $this->revoked(Uuid::v7())];
        $calls = 0;
        $auditLogger = self::createStub(SecurityAuditLoggerInterface::class);
        $auditLogger->method('mcpConnectionSuperseded')->willReturnCallback(static function () use (&$calls): void {
            if (1 === ++$calls) {
                throw new RuntimeException('connexion citée 01929a4e');
            }
        });

        $this->supersession(auditLogger: $auditLogger)->auditSuperseded(new Supersession(Uuid::v7(), $revoked));

        self::assertSame(2, $calls, 'La panne de la première ligne a tu la seconde.');
        $records = $this->mainChannel->getRecords();
        self::assertCount(1, $records);
        self::assertSame(Level::Critical, $records[0]->level);
        self::assertSame('Superseded connection not audited.', $records[0]->message);
        self::assertSame(['exceptionClass' => RuntimeException::class], $records[0]->context);
    }

    /** Un remplacement vide n'écrit rien. */
    public function testAnEmptySupersessionAuditsNothing(): void
    {
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('mcpConnectionSuperseded');

        $this->supersession(auditLogger: $auditLogger)->auditSuperseded(new Supersession(Uuid::v7(), []));

        self::assertSame([], $this->mainChannel->getRecords());
    }

    private function supersession(
        ?ExchangedCodeConnectionInterface $exchangedCode = null,
        ?McpConnectionRepositoryInterface $connections = null,
        ?SecurityAuditLoggerInterface $auditLogger = null,
    ): CodeExchangeSupersession {
        return new CodeExchangeSupersession(
            $exchangedCode ?? self::createStub(ExchangedCodeConnectionInterface::class),
            $connections ?? self::createStub(McpConnectionRepositoryInterface::class),
            new MockClock(new DateTimeImmutable(self::NOW)),
            $auditLogger ?? self::createStub(SecurityAuditLoggerInterface::class),
            new FailSafeAudit(new Logger('app', [$this->mainChannel])),
        );
    }

    /** Un échange de code tel que FormEncodedRequest l'a analysé ; le code n'est lu que par le port. */
    private function codeExchange(): ServerRequest
    {
        return new ServerRequest('POST', '/api/oauth/token')->withParsedBody(['grant_type' => 'authorization_code', 'code' => 'un-code']);
    }

    private function connection(): McpConnection
    {
        return McpConnection::grant(
            new CpgUser('jane', 'hashed-password'),
            RegisteredMcpClient::of(self::CLIENT_ID, 'Client de test', []),
            McpConnection::SCOPE_NOMINATIVE_READ,
            new DateTimeImmutable(self::NOW),
        );
    }

    private function revoked(Uuid $connectionId): RevokedConnection
    {
        return new RevokedConnection(new ConnectionReference($connectionId, self::ACCOUNT_ID, self::CLIENT_ID), RevocationCause::Superseded);
    }
}
