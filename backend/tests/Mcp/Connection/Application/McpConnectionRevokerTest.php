<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Application;

use App\Mcp\Connection\Application\McpConnectionRevoker;
use App\Mcp\Connection\Domain\Exception\AuthorizationCodeReplayedException;
use App\Mcp\Connection\Domain\Exception\CompromisedMcpConnectionException;
use App\Mcp\Connection\Domain\Exception\RotatedRefreshTokenReusedException;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\ConnectionReference;
use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use App\Mcp\Connection\Domain\ValueObject\RevokedConnection;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use Closure;
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
 *
 * Typé par la compromission (#726, CR6) : le jeu de données est ses deux
 * sous-classes. Les causes réservées (`expired`, la purge ; `superseded`,
 * l'échange) ne se demandent plus — plus de refus à l'exécution à tester,
 * PHPStan refuse l'appel.
 */
final class McpConnectionRevokerTest extends TestCase
{
    private const string NOW = '2026-10-25T02:30:00+02:00';
    private const string ACCOUNT_ID = '01929a4e-1111-7000-8000-000000000001';
    private const string CLIENT_ID = 'https://client.example.test/metadata.json';

    /**
     * Les deux compromissions, seules à atteindre le révocateur (#726, CR6) :
     * l'exception scellée porte la connexion et la cause.
     *
     * @return iterable<string, array{Closure(Uuid): CompromisedMcpConnectionException, RevocationCause}>
     */
    public static function compromises(): iterable
    {
        yield 'rafraîchissement réutilisé' => [RotatedRefreshTokenReusedException::forConnection(...), RevocationCause::RefreshTokenReuse];
        yield 'code rejoué' => [AuthorizationCodeReplayedException::forConnection(...), RevocationCause::CodeReplay];
    }

    /**
     * @param Closure(Uuid): CompromisedMcpConnectionException $compromiseOf
     */
    #[DataProvider('compromises')]
    public function testARevocationIsAuditedOnce(Closure $compromiseOf, RevocationCause $cause): void
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
        $auditLogger->expects(self::never())->method('mcpConnectionSuperseded');

        $result = $this->revoker($connections, $auditLogger)->revoke($compromiseOf($connectionId));

        self::assertSame($revoked, $result);
    }

    /**
     * Inconnue ou déjà révoquée : le dépôt ne rend rien, rien n'est audité —
     * la première révocation a déjà la sienne.
     *
     * @param Closure(Uuid): CompromisedMcpConnectionException $compromiseOf
     */
    #[DataProvider('compromises')]
    public function testNothingIsAuditedWhenTheConnectionWasAlreadyRevoked(Closure $compromiseOf, RevocationCause $cause): void
    {
        $connections = $this->createMock(McpConnectionRepositoryInterface::class);
        $connections->expects(self::once())->method('revokeActive')
            ->with(self::isInstanceOf(Uuid::class), $cause, self::isInstanceOf(DateTimeImmutable::class))
            ->willReturn(null);
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('mcpConnectionRevoked');
        $auditLogger->expects(self::never())->method('mcpConnectionExpired');

        self::assertNull($this->revoker($connections, $auditLogger)->revoke($compromiseOf(Uuid::v7())));
    }

    private function revoker(McpConnectionRepositoryInterface $connections, SecurityAuditLoggerInterface $auditLogger): McpConnectionRevoker
    {
        return new McpConnectionRevoker($connections, $auditLogger, new MockClock(new DateTimeImmutable(self::NOW)));
    }
}
