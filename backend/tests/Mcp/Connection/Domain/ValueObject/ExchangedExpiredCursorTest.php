<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Domain\ValueObject;

use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\ValueObject\ExchangedExpiredCursor;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Mcp\Connection\RegisteredMcpClient;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Le curseur de la lecture par pages des expirées échangées (#652) : la clé de
 * reprise `(COALESCE(last_used_at, granted_at), id)` de la dernière ligne lue,
 * soit l'activité de la connexion et son identifiant.
 */
final class ExchangedExpiredCursorTest extends TestCase
{
    public function testTheActivityOfAConnectionNeverUsedIsItsGrant(): void
    {
        $grantedAt = new DateTimeImmutable('2026-11-20T10:00:00+01:00');
        $connection = $this->grantedAt($grantedAt);

        $cursor = ExchangedExpiredCursor::after($connection);

        self::assertEquals($grantedAt, $cursor->activity);
        self::assertTrue($connection->getId()->equals($cursor->id));
    }

    public function testTheActivityOfAConnectionUsedIsItsLastUse(): void
    {
        $connection = $this->grantedAt(new DateTimeImmutable('2026-11-20T10:00:00+01:00'));
        $usedAt = new DateTimeImmutable('2026-11-21T09:00:00+01:00');
        $connection->recordUseAt($usedAt);

        self::assertEquals($usedAt, ExchangedExpiredCursor::after($connection)->activity);
    }

    public function testTheCursorKeepsTheInstantAndTheIdItWasGiven(): void
    {
        $activity = new DateTimeImmutable('2026-11-19T03:00:00+01:00');
        $id = Uuid::v7();

        $cursor = new ExchangedExpiredCursor($activity, $id);

        self::assertSame($activity, $cursor->activity);
        self::assertSame($id, $cursor->id);
    }

    private function grantedAt(DateTimeImmutable $grantedAt): McpConnection
    {
        return McpConnection::grant(
            new CpgUser('jane', 'hashed-password'),
            RegisteredMcpClient::of('https://client.example.test/metadata.json', 'Client de test', ['https://client.example.test/callback']),
            McpConnection::SCOPE_NOMINATIVE_READ,
            $grantedAt,
        );
    }
}
