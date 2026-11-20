<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Domain\ValueObject;

use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\Exception\ConnectionNeverRevokedException;
use App\Mcp\Connection\Domain\ValueObject\ExchangedExpiredCursor;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Mcp\Connection\RegisteredMcpClient;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Le curseur de la lecture par pages des expirées échangées (#652) : la clé de
 * reprise `(revoked_at, id)` de la dernière ligne lue. Une connexion sans
 * `revoked_at` n'a pas de place dans cet ordre : la fabrique le refuse par une
 * exception métier, au lieu de laisser un `null` devenir un paramètre de requête
 * (la page suivante serait alors vide, silencieusement).
 *
 * La construction depuis une connexion révoquée se prouve dans
 * `McpConnectionRepositoryTest` : le modèle n'a aucun mutateur de `revokedAt`,
 * seul le dépôt la pose.
 */
final class ExchangedExpiredCursorTest extends TestCase
{
    public function testACursorCannotBeBuiltFromAConnectionThatWasNeverRevoked(): void
    {
        $connection = McpConnection::grant(
            new CpgUser('jane', 'hashed-password'),
            RegisteredMcpClient::of('https://client.example.test/metadata.json', 'Client de test', ['https://client.example.test/callback']),
            McpConnection::SCOPE_NOMINATIVE_READ,
            new DateTimeImmutable('2026-11-20T10:00:00+01:00'),
        );

        $this->expectException(ConnectionNeverRevokedException::class);

        ExchangedExpiredCursor::after($connection);
    }

    public function testTheCursorKeepsTheInstantAndTheIdItWasGiven(): void
    {
        $revokedAt = new DateTimeImmutable('2026-11-19T03:00:00+01:00');
        $id = Uuid::v7();

        $cursor = new ExchangedExpiredCursor($revokedAt, $id);

        self::assertSame($revokedAt, $cursor->revokedAt);
        self::assertSame($id, $cursor->id);
    }
}
