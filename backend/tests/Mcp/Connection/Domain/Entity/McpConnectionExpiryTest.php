<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Domain\Entity;

use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\ValueObject\ConnectionLifetimes;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Mcp\Connection\RegisteredMcpClient;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * L'échéance d'une connexion MCP (#649, revue n° 4), jugée par
 * `McpConnection::isExpiredAt()` sur les seuils de ConnectionLifetimes : à la
 * seconde de son échéance la connexion est morte, la seconde d'avant vivante,
 * et l'échéance tombe à la même heure murale de Paris quand un changement
 * d'heure sépare l'octroi de l'instant jugé.
 */
final class McpConnectionExpiryTest extends TestCase
{
    private DateTimeZone $paris;

    protected function setUp(): void
    {
        $this->paris = new DateTimeZone('Europe/Paris');
    }

    /**
     * Octroyée le 26 septembre à 10 h, jamais utilisée : le délai
     * d'inactivité (30 jours, 721 heures à travers le passage à l'heure
     * d'hiver) l'emporte le 26 octobre à 10 h, heure de Paris.
     */
    public function testAConnectionExpiresAtItsIdleThresholdAcrossTheClockChange(): void
    {
        $lifetimes = ConnectionLifetimes::fromConfiguration('PT10M', 'PT1H', 'P30D', 'P30D', 'P90D');
        $connection = $this->connectionGrantedAt($this->paris('2026-09-26 10:00:00'));

        self::assertFalse($connection->isExpiredAt($this->paris('2026-10-26 09:59:59'), $lifetimes));
        self::assertTrue($connection->isExpiredAt($this->paris('2026-10-26 10:00:00'), $lifetimes));
    }

    /**
     * La durée maximale ne dépend pas de l'usage : une inactivité portée à
     * 90 jours et un dernier usage une heure avant isolent la borne absolue,
     * atteinte 90 jours de calendrier après l'octroi.
     */
    public function testAConnectionExpiresAtItsMaximumWhateverItsUse(): void
    {
        $lifetimes = ConnectionLifetimes::fromConfiguration('PT10M', 'PT1H', 'P30D', 'P90D', 'P90D');
        $connection = $this->connectionGrantedAt($this->paris('2026-07-28 10:00:00'));
        // Le dernier usage n'a pas encore de mutateur (tâche suivante de #649) :
        // posé par réflexion, comme la base le rendrait.
        new ReflectionProperty(McpConnection::class, 'lastUsedAt')->setValue($connection, $this->paris('2026-10-26 09:00:00'));

        self::assertFalse($connection->isExpiredAt($this->paris('2026-10-26 09:59:59'), $lifetimes));
        self::assertTrue($connection->isExpiredAt($this->paris('2026-10-26 10:00:00'), $lifetimes));
    }

    private function connectionGrantedAt(DateTimeImmutable $grantedAt): McpConnection
    {
        return McpConnection::grant(
            new CpgUser('jane', 'hashed-password'),
            RegisteredMcpClient::of('https://client.example.test/metadata.json', 'Client de test', []),
            McpConnection::SCOPE_NOMINATIVE_READ,
            $grantedAt,
        );
    }

    private function paris(string $wallTime): DateTimeImmutable
    {
        return new DateTimeImmutable($wallTime, $this->paris);
    }
}
