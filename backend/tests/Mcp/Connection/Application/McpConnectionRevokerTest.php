<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Application;

use App\Mcp\Connection\Application\McpConnectionRevoker;
use App\Mcp\Connection\Application\RevocationCauseReservedToTheExchangeException;
use App\Mcp\Connection\Application\RevocationCauseReservedToThePurgeException;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\ConnectionReference;
use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use App\Mcp\Connection\Domain\ValueObject\RevokedConnection;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * McpConnectionRevoker (#649) sur un dépôt et un journal simulés : la
 * révocation passe par le dépôt, à l'instant de l'horloge, et n'est auditée
 * que si elle a eu lieu — une fois, avec ce que le dépôt a rendu, jamais ce
 * que l'appelant croyait savoir.
 */
final class McpConnectionRevokerTest extends TestCase
{
    private const string NOW = '2026-10-25T02:30:00+02:00';
    private const string ACCOUNT_ID = '01929a4e-1111-7000-8000-000000000001';
    private const string CLIENT_ID = 'https://client.example.test/metadata.json';

    /** @return iterable<string, array{RevocationCause}> */
    public static function auditedAsRevocations(): iterable
    {
        yield 'révoquée par le client' => [RevocationCause::Client];
        yield 'rafraîchissement réutilisé' => [RevocationCause::RefreshTokenReuse];
        yield 'code rejoué' => [RevocationCause::CodeReplay];
    }

    #[DataProvider('auditedAsRevocations')]
    public function testARevocationIsAuditedOnce(RevocationCause $cause): void
    {
        $connectionId = Uuid::v7();
        $revoked = new RevokedConnection(new ConnectionReference($connectionId, self::ACCOUNT_ID, self::CLIENT_ID), $cause);
        $connections = $this->createMock(McpConnectionRepositoryInterface::class);
        $connections->expects(self::once())
            ->method('revokeActive')
            ->with(
                self::callback(static fn (Uuid $id): bool => $id->equals($connectionId)),
                $cause,
                self::callback(static fn (DateTimeImmutable $at): bool => new DateTimeImmutable(self::NOW)->getTimestamp() === $at->getTimestamp()),
            )
            ->willReturn($revoked);
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('mcpConnectionRevoked')
            ->with($revoked, $cause->value);
        $auditLogger->expects(self::never())->method('mcpConnectionExpired');

        $result = $this->revoker($connections, $auditLogger)->revoke($connectionId, $cause);

        self::assertSame($revoked, $result);
    }

    /**
     * L'expiration n'est pas une cause du révocateur : la purge planifiée la
     * constate et la révoque elle-même, dans sa transaction, et l'audite avec
     * l'acteur `system` après le COMMIT (McpConnectionPurger). Demandée ici,
     * c'est un défaut du programme : refusée avant toute écriture, rien
     * audité — ni comme une révocation, ni comme une expiration.
     */
    public function testTheExpiredCauseIsRefusedBeforeAnyWrite(): void
    {
        $connections = $this->createMock(McpConnectionRepositoryInterface::class);
        $connections->expects(self::never())->method('revokeActive');
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('mcpConnectionRevoked');
        $auditLogger->expects(self::never())->method('mcpConnectionExpired');

        $this->expectException(RevocationCauseReservedToThePurgeException::class);

        $this->revoker($connections, $auditLogger)->revoke(Uuid::v7(), RevocationCause::Expired);
    }

    /**
     * Le remplacement n'est pas une cause du révocateur (#726, B3) : le point
     * de jeton révoque les autres connexions du couple dans la transaction de
     * l'échange et audite chacune après le COMMIT en nommant la connexion qui
     * la remplace (`supersededBy`). Demandé ici, la ligne n'aurait pas ce
     * champ : refusé avant toute écriture, rien audité — ni comme une
     * révocation, ni comme un remplacement.
     */
    public function testTheSupersededCauseIsRefusedBeforeAnyWrite(): void
    {
        $connections = $this->createMock(McpConnectionRepositoryInterface::class);
        $connections->expects(self::never())->method('revokeActive');
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('mcpConnectionRevoked');
        $auditLogger->expects(self::never())->method('mcpConnectionSuperseded');

        $this->expectException(RevocationCauseReservedToTheExchangeException::class);

        $this->revoker($connections, $auditLogger)->revoke(Uuid::v7(), RevocationCause::Superseded);
    }

    /**
     * Inconnue ou déjà révoquée : le dépôt ne rend rien, rien n'est audité —
     * la première révocation a déjà sa ligne.
     */
    public function testNothingIsAuditedWhenTheConnectionWasAlreadyRevoked(): void
    {
        $connections = self::createStub(McpConnectionRepositoryInterface::class);
        $connections->method('revokeActive')->willReturn(null);
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('mcpConnectionRevoked');
        $auditLogger->expects(self::never())->method('mcpConnectionExpired');

        self::assertNull($this->revoker($connections, $auditLogger)->revoke(Uuid::v7(), RevocationCause::Client));
    }

    private function revoker(McpConnectionRepositoryInterface $connections, SecurityAuditLoggerInterface $auditLogger): McpConnectionRevoker
    {
        return new McpConnectionRevoker($connections, $auditLogger, new MockClock(new DateTimeImmutable(self::NOW)));
    }
}
