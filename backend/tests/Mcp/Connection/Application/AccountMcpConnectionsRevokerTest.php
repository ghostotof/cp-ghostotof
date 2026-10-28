<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Application;

use App\Mcp\Connection\Application\AccountMcpConnectionsRevoker;
use App\Mcp\Connection\Application\FailSafeAudit;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\ConnectionReference;
use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use App\Mcp\Connection\Domain\ValueObject\RevokedConnection;
use App\Mcp\Connection\Infrastructure\Doctrine\RevocationOutsideTransactionException;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Shared\Application\Security\AccountAccessWithdrawal;
use App\Shared\Application\Security\RevokedAccountMcpConnections;
use App\Shared\Domain\Security\AccountReference;
use App\Shared\Domain\Security\McpConnectionReference;
use DateTimeImmutable;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * Le port de `Shared` implémenté par `Mcp/Connection` (#650, tâche 5) sur un
 * dépôt et un journal simulés : la cause du dépôt se déduit de la raison du
 * port par un `match` exhaustif ; `revokeAllOf()` n'audite rien (l'audit suit
 * le COMMIT de l'appelant, `auditRevoked()`) ; chaque ligne est tentée à part,
 * la panne de l'une ne taisant ni n'annulant les autres.
 */
final class AccountMcpConnectionsRevokerTest extends TestCase
{
    private const string NOW = '2026-10-28T10:00:00+01:00';
    private const string ACCOUNT_ID = '01929a4e-1111-7000-8000-000000000001';
    private const string CLIENT_ID = 'https://client.example.test/metadata.json';
    private const string NOT_AUDITED = 'Revoked connection not audited.';

    /**
     * @return iterable<string, array{AccountAccessWithdrawal, RevocationCause}>
     */
    public static function reasons(): iterable
    {
        yield 'mot de passe changé' => [AccountAccessWithdrawal::PasswordChanged, RevocationCause::PasswordChanged];
        yield 'palier de confiance retiré' => [AccountAccessWithdrawal::TrustedTierWithdrawn, RevocationCause::TrustedTierWithdrawn];
    }

    /**
     * La raison du port devient la cause du dépôt, le compte en UUID et
     * l'instant celui de l'horloge ; ce que le dépôt a révoqué ressort, sous
     * la raison. Aucun audit : l'appelant n'a pas encore validé.
     */
    #[DataProvider('reasons')]
    public function testItRevokesEveryConnectionWithTheCauseOfTheReason(AccountAccessWithdrawal $reason, RevocationCause $cause): void
    {
        $first = $this->revoked($cause);
        $second = $this->revoked($cause);
        $connections = $this->createMock(McpConnectionRepositoryInterface::class);
        $connections->expects(self::once())->method('revokeAllActiveOfAccount')
            ->with(
                self::callback(static fn (Uuid $id): bool => self::ACCOUNT_ID === $id->toRfc4122()),
                $cause,
                self::callback(static fn (DateTimeImmutable $at): bool => new DateTimeImmutable(self::NOW)->getTimestamp() === $at->getTimestamp()),
            )
            ->willReturn([$first, $second]);
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('mcpConnectionRevoked');

        $result = $this->revoker($connections, $auditLogger)->revokeAllOf($this->account(), $reason);

        self::assertSame($reason, $result->reason());
        self::assertSame([$first, $second], $result->connections());
    }

    /** Un compte sans connexion active : une liste vide, rien à auditer. */
    public function testAnAccountWithoutActiveConnectionsRevokesNothing(): void
    {
        $connections = self::createStub(McpConnectionRepositoryInterface::class);
        $connections->method('revokeAllActiveOfAccount')->willReturn([]);

        $result = $this->revoker($connections, self::createStub(SecurityAuditLoggerInterface::class))
            ->revokeAllOf($this->account(), AccountAccessWithdrawal::PasswordChanged);

        self::assertSame([], $result->connections());
    }

    /**
     * Le refus du dépôt hors transaction remonte tel quel : rien n'est rendu,
     * donc rien ne sera audité (la révocation n'a pas eu lieu).
     */
    public function testARefusalOfTheRepositoryEscapesAndAuditsNothing(): void
    {
        $connections = self::createStub(McpConnectionRepositoryInterface::class);
        $connections->method('revokeAllActiveOfAccount')->willThrowException(RevocationOutsideTransactionException::forAccount(self::ACCOUNT_ID));
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('mcpConnectionRevoked');

        $this->expectException(RevocationOutsideTransactionException::class);

        $this->revoker($connections, $auditLogger)->revokeAllOf($this->account(), AccountAccessWithdrawal::PasswordChanged);
    }

    /**
     * Une ligne `mcp-connection-revoked` par connexion, avec la cause de la
     * raison. La seconde tombe en panne : la première est écrite, une ligne
     * `critical` (la classe seule) dit la seconde manquée, rien n'est levé.
     */
    #[DataProvider('reasons')]
    public function testEachRevokedConnectionIsAuditedOnceAfterwards(AccountAccessWithdrawal $reason, RevocationCause $cause): void
    {
        $first = $this->revoked($cause);
        $second = $this->revoked($cause);
        $written = [];
        $auditLogger = self::createStub(SecurityAuditLoggerInterface::class);
        $auditLogger->method('mcpConnectionRevoked')->willReturnCallback(
            static function (McpConnectionReference $connection, string $recordedCause) use (&$written, $second): void {
                if ($connection === $second) {
                    throw new RuntimeException('Journal de sécurité indisponible');
                }
                $written[] = [$connection, $recordedCause];
            },
        );
        $logs = new TestHandler();

        $this->revoker(self::createStub(McpConnectionRepositoryInterface::class), $auditLogger, new Logger('app', [$logs]))
            ->auditRevoked(new RevokedAccountMcpConnections($reason, [$first, $second]));

        self::assertSame([[$first, $cause->value]], $written);
        self::assertCount(1, $logs->getRecords());
        $record = $logs->getRecords()[0];
        self::assertSame(self::NOT_AUDITED, $record->message);
        self::assertSame(Level::Critical, $record->level);
        self::assertSame(['exceptionClass' => RuntimeException::class], $record->context);
    }

    /** La panne de la première ligne ne taise pas la seconde : chacune est tentée à part. */
    public function testAFailingLineDoesNotSkipTheNext(): void
    {
        $first = $this->revoked(RevocationCause::PasswordChanged);
        $second = $this->revoked(RevocationCause::PasswordChanged);
        $written = [];
        $auditLogger = self::createStub(SecurityAuditLoggerInterface::class);
        $auditLogger->method('mcpConnectionRevoked')->willReturnCallback(
            static function (McpConnectionReference $connection) use (&$written, $first): void {
                if ($connection === $first) {
                    throw new RuntimeException('Journal de sécurité indisponible');
                }
                $written[] = $connection;
            },
        );

        $this->revoker(self::createStub(McpConnectionRepositoryInterface::class), $auditLogger)
            ->auditRevoked(new RevokedAccountMcpConnections(AccountAccessWithdrawal::PasswordChanged, [$first, $second]));

        self::assertSame([$second], $written);
    }

    /** Rien de révoqué, rien d'écrit. */
    public function testNothingRevokedWritesNoLine(): void
    {
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('mcpConnectionRevoked');

        $this->revoker(self::createStub(McpConnectionRepositoryInterface::class), $auditLogger)
            ->auditRevoked(new RevokedAccountMcpConnections(AccountAccessWithdrawal::TrustedTierWithdrawn, []));
    }

    private function revoker(McpConnectionRepositoryInterface $connections, SecurityAuditLoggerInterface $auditLogger, ?Logger $logger = null): AccountMcpConnectionsRevoker
    {
        return new AccountMcpConnectionsRevoker(
            $connections,
            new MockClock(new DateTimeImmutable(self::NOW)),
            $auditLogger,
            new FailSafeAudit($logger ?? new Logger('app')),
        );
    }

    private function account(): AccountReference
    {
        $account = self::createStub(AccountReference::class);
        $account->method('accountId')->willReturn(self::ACCOUNT_ID);

        return $account;
    }

    private function revoked(RevocationCause $cause): RevokedConnection
    {
        return new RevokedConnection(new ConnectionReference(Uuid::v7(), self::ACCOUNT_ID, self::CLIENT_ID), $cause);
    }
}
