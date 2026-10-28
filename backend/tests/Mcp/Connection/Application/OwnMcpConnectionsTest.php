<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Application;

use App\Mcp\Connection\Application\FailSafeAudit;
use App\Mcp\Connection\Application\OwnMcpConnections;
use App\Mcp\Connection\Application\OwnMcpConnectionView;
use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\Exception\McpConnectionNotFoundException;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\ConnectionLifetimes;
use App\Mcp\Connection\Domain\ValueObject\ConnectionReference;
use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use App\Mcp\Connection\Domain\ValueObject\RevokedConnection;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Shared\Domain\Security\AccountReference;
use App\Tests\Mcp\Connection\RegisteredMcpClient;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * La révocation par la personne (#650) de {@see OwnMcpConnections}, sur un
 * dépôt et un journal simulés : la cause est toujours `person`, à l'instant de
 * l'horloge, avec le compte de l'appelant pour filtre ; l'audit suit
 * l'écriture et n'a lieu que si elle a eu lieu ; « pas à vous » et « inconnue »
 * sont la même exception.
 */
final class OwnMcpConnectionsTest extends TestCase
{
    private const string NOW = '2026-10-28T10:00:00+01:00';
    private const string ACCOUNT_ID = '01929a4e-1111-7000-8000-000000000001';
    private const string CLIENT_ID = 'https://client.example.test/metadata.json';

    /** Révoquée par cet appel : un audit, la cause `person`, ce que le dépôt a rendu. */
    public function testARevokedConnectionIsAuditedOncePerson(): void
    {
        $connectionId = Uuid::v7();
        $revoked = new RevokedConnection(new ConnectionReference($connectionId, self::ACCOUNT_ID, self::CLIENT_ID), RevocationCause::Person);
        $connections = $this->createMock(McpConnectionRepositoryInterface::class);
        $connections->expects(self::once())->method('revokeActiveOfAccount')
            ->with(
                self::callback(static fn(Uuid $id): bool => $id->equals($connectionId)),
                self::callback(static fn(Uuid $id): bool => self::ACCOUNT_ID === $id->toRfc4122()),
                RevocationCause::Person,
                self::callback(static fn(DateTimeImmutable $at): bool => new DateTimeImmutable(self::NOW)->getTimestamp() === $at->getTimestamp()),
            )
            ->willReturn($revoked);
        $connections->expects(self::never())->method('belongsToAccount');
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('mcpConnectionRevoked')->with($revoked, 'person');
        $auditLogger->expects(self::never())->method('mcpConnectionExpired');
        $auditLogger->expects(self::never())->method('mcpConnectionSuperseded');

        $this->service($connections, $auditLogger)->revoke($this->account(), $connectionId);
    }

    /** Déjà révoquée, mais à elle : rien n'est écrit ni audité, aucune exception (DELETE idempotent). */
    public function testAnAlreadyRevokedConnectionOfTheCallerIsAuditedNever(): void
    {
        $connections = self::createStub(McpConnectionRepositoryInterface::class);
        $connections->method('revokeActiveOfAccount')->willReturn(null);
        $connections->method('belongsToAccount')->willReturn(true);
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('mcpConnectionRevoked');

        $this->service($connections, $auditLogger)->revoke($this->account(), Uuid::v7());
    }

    /** Ni révoquée ni à elle (autre compte ou inconnue) : l'exception, aucun audit. */
    public function testAConnectionThatIsNotTheCallersIsNotFound(): void
    {
        $connections = self::createStub(McpConnectionRepositoryInterface::class);
        $connections->method('revokeActiveOfAccount')->willReturn(null);
        $connections->method('belongsToAccount')->willReturn(false);
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('mcpConnectionRevoked');

        $this->expectException(McpConnectionNotFoundException::class);

        $this->service($connections, $auditLogger)->revoke($this->account(), Uuid::v7());
    }

    /** Une panne de l'audit, après l'écriture, ne remonte pas (FailSafeAudit). */
    public function testAnAuditFailureIsNotRaised(): void
    {
        $revoked = new RevokedConnection(new ConnectionReference(Uuid::v7(), self::ACCOUNT_ID, self::CLIENT_ID), RevocationCause::Person);
        $connections = self::createStub(McpConnectionRepositoryInterface::class);
        $connections->method('revokeActiveOfAccount')->willReturn($revoked);
        $auditLogger = self::createStub(SecurityAuditLoggerInterface::class);
        $auditLogger->method('mcpConnectionRevoked')->willThrowException(new RuntimeException('Journal indisponible'));

        $this->service($connections, $auditLogger)->revoke($this->account(), Uuid::v7());

        $this->addToAssertionCount(1);
    }

    /**
     * La liste (#650, R3) : toutes les non révoquées, plus les terminées sous
     * la limite. Le service en demande **une de plus** que la limite : c'est
     * l'existence de cette ligne en trop qui dit « il y en a davantage », sans
     * second `COUNT`.
     */
    public function testItAsksForOneMoreTerminatedThanItShowsAndReportsTheTruncation(): void
    {
        $account = $this->account();
        $connections = $this->createMock(McpConnectionRepositoryInterface::class);
        $connections->method('findNotRevokedOfAccount')->willReturn([]);
        $connections->expects(self::once())->method('findTerminatedOfAccount')
            ->with(self::callback(static fn(Uuid $id): bool => self::ACCOUNT_ID === $id->toRfc4122()), OwnMcpConnections::TERMINATED_LIMIT + 1)
            ->willReturn($this->connectionsGrantedEveryHour(OwnMcpConnections::TERMINATED_LIMIT + 1));

        $list = $this->service($connections, self::createStub(SecurityAuditLoggerInterface::class))->of($account);

        self::assertTrue($list->terminatedTruncated);
        self::assertCount(OwnMcpConnections::TERMINATED_LIMIT, $list->connections);
    }

    /** Exactement la limite : rien n'est coupé, `terminatedTruncated` reste faux. */
    public function testExactlyTheLimitOfTerminatedConnectionsIsNotATruncation(): void
    {
        $connections = self::createStub(McpConnectionRepositoryInterface::class);
        $connections->method('findNotRevokedOfAccount')->willReturn([]);
        $connections->method('findTerminatedOfAccount')->willReturn($this->connectionsGrantedEveryHour(OwnMcpConnections::TERMINATED_LIMIT));

        $list = $this->service($connections, self::createStub(SecurityAuditLoggerInterface::class))->of($this->account());

        self::assertFalse($list->terminatedTruncated);
        self::assertCount(OwnMcpConnections::TERMINATED_LIMIT, $list->connections);
    }

    /**
     * Les deux moitiés se rangent en une seule liste, la plus récemment
     * octroyée d'abord : une non révoquée ancienne se place après les
     * terminées plus récentes, pas en tête ni en queue par construction.
     */
    public function testBothHalvesAreMergedNewestGrantFirst(): void
    {
        $terminated = $this->connectionsGrantedEveryHour(2);
        $oldActive = $this->connection(new DateTimeImmutable('2025-01-01T10:00:00+01:00'));
        $newActive = $this->connection(new DateTimeImmutable('2026-10-28T09:30:00+01:00'));
        $connections = self::createStub(McpConnectionRepositoryInterface::class);
        $connections->method('findNotRevokedOfAccount')->willReturn([$newActive, $oldActive]);
        $connections->method('findTerminatedOfAccount')->willReturn($terminated);

        $list = $this->service($connections, self::createStub(SecurityAuditLoggerInterface::class))->of($this->account());

        self::assertSame(
            [$newActive->getId()->toRfc4122(), $terminated[0]->getId()->toRfc4122(), $terminated[1]->getId()->toRfc4122(), $oldActive->getId()->toRfc4122()],
            array_map(static fn(OwnMcpConnectionView $view): string => $view->id->toRfc4122(), $list->connections),
        );
        self::assertFalse($list->terminatedTruncated);
    }

    /**
     * @return list<McpConnection> la plus récemment octroyée d'abord, une heure d'écart
     */
    private function connectionsGrantedEveryHour(int $count): array
    {
        $connections = [];
        $base = new DateTimeImmutable(self::NOW);
        for ($hour = 1; $hour <= $count; ++$hour) {
            $connections[] = $this->connection($base->modify(\sprintf('-%d hours', $hour)));
        }

        return $connections;
    }

    private function connection(DateTimeImmutable $grantedAt): McpConnection
    {
        $connection = McpConnection::grant(
            new CpgUser('jane', 'hashed-password'),
            RegisteredMcpClient::of(self::CLIENT_ID, 'Client de test', ['https://client.example.test/callback']),
            'nominative:read',
            $grantedAt,
        );
        $connection->recordCodeExchangeAt($grantedAt);

        return $connection;
    }

    private function service(McpConnectionRepositoryInterface $connections, SecurityAuditLoggerInterface $auditLogger): OwnMcpConnections
    {
        return new OwnMcpConnections(
            $connections,
            new MockClock(new DateTimeImmutable(self::NOW)),
            ConnectionLifetimes::fromConfiguration('PT10M', 'PT1H', 'P30D', 'P30D', 'P90D', 'PT1H'),
            $auditLogger,
            new FailSafeAudit(new NullLogger()),
        );
    }

    private function account(): AccountReference
    {
        $account = self::createStub(AccountReference::class);
        $account->method('accountId')->willReturn(self::ACCOUNT_ID);

        return $account;
    }
}
