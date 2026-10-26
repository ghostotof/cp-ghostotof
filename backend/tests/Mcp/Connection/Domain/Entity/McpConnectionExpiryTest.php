<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Domain\Entity;

use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\ValueObject\ConnectionLifetimes;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Mcp\Connection\RegisteredMcpClient;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * L'échéance d'une connexion MCP (#649, revue n° 4), jugée par
 * `McpConnection::isExpiredAt()` sur les échéances de ConnectionLifetimes : à
 * la seconde de son échéance la connexion est morte, la seconde d'avant
 * vivante ; l'échéance tombe à la même heure murale de Paris quand un
 * changement d'heure sépare l'octroi de l'instant jugé ; et l'expiration est
 * **monotone** — morte à un instant, morte à tout instant suivant, heure
 * répétée de l'automne et heure sautée du printemps comprises.
 */
final class McpConnectionExpiryTest extends TestCase
{
    private DateTimeZone $paris;

    private ConnectionLifetimes $lifetimes;

    protected function setUp(): void
    {
        $this->paris = new DateTimeZone('Europe/Paris');
        $this->lifetimes = ConnectionLifetimes::fromConfiguration('PT10M', 'PT1H', 'P30D', 'P30D', 'P90D');
    }

    /**
     * Octroyée le 26 septembre à 10 h, jamais utilisée : le délai
     * d'inactivité (30 jours, 721 heures à travers le passage à l'heure
     * d'hiver) l'emporte le 26 octobre à 10 h, heure de Paris.
     */
    public function testAConnectionExpiresAtItsIdleDeadlineAcrossTheClockChange(): void
    {
        $connection = $this->connectionGrantedAt($this->paris('2026-09-26 10:00:00'));

        self::assertFalse($connection->isExpiredAt($this->paris('2026-10-26 09:59:59'), $this->lifetimes));
        self::assertTrue($connection->isExpiredAt($this->paris('2026-10-26 10:00:00'), $this->lifetimes));
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

    /**
     * Doctrine hydrate une colonne `datetimetz_immutable` avec un décalage
     * fixe (`+02:00`), sur lequel 30 jours dureraient 720 heures : la connexion
     * lue en base expirerait une heure plus tôt que la même en mémoire.
     * L'échéance se compte dans le fuseau de l'instant jugé.
     */
    public function testAGrantHydratedWithAFixedOffsetExpiresOnTheParisCalendar(): void
    {
        $connection = $this->connectionGrantedAt(new DateTimeImmutable('2026-09-26 10:00:00+02:00'));

        self::assertFalse($connection->isExpiredAt($this->paris('2026-10-26 09:59:59'), $this->lifetimes));
        self::assertTrue($connection->isExpiredAt($this->paris('2026-10-26 10:00:00'), $this->lifetimes));
    }

    /**
     * La reproduction de la relecture : jugée sur un seuil (`$now` moins
     * 30 jours), cette connexion était morte à 00:45Z (02:45 CEST), vivante à
     * 01:15Z (02:15 CET, l'heure répétée) et morte de nouveau à 01:45Z. Son
     * échéance, 02:30 CEST (00:30Z), est fixe : morte aux trois instants.
     */
    public function testAConnectionDeadInTheRepeatedAutumnHourStaysDead(): void
    {
        $connection = $this->connectionGrantedAt(new DateTimeImmutable('2026-09-25T02:30:00+02:00'));

        foreach (['2026-10-25T00:45:00Z', '2026-10-25T01:15:00Z', '2026-10-25T01:45:00Z'] as $instant) {
            $now = new DateTimeImmutable($instant)->setTimezone($this->paris);

            self::assertTrue($connection->isExpiredAt($now, $this->lifetimes), $instant);
        }
    }

    /**
     * Des octrois dont l'échéance tombe dans la fenêtre d'un changement
     * d'heure, à 01:30, 02:00, 02:30 et 03:00 heure de Paris, 30 jours plus
     * tôt : à l'automne l'heure de 02:00 à 03:00 se répète, au printemps elle
     * n'existe pas.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function clockChangeWindows(): iterable
    {
        foreach (['01:30', '02:00', '02:30', '03:00'] as $wallTime) {
            yield 'automne, octroi '.$wallTime => ['2026-09-25 '.$wallTime.':00', '2026-10-24T22:00:00Z', '2026-10-25T04:00:00Z'];
            yield 'printemps, octroi '.$wallTime => ['2026-02-27 '.$wallTime.':00', '2026-03-28T23:00:00Z', '2026-03-29T04:00:00Z'];
        }
    }

    /**
     * Toutes les 15 minutes à travers la fenêtre (heure de Paris) : vivante
     * au début, morte à la fin, et jamais vivante de nouveau une fois morte.
     */
    #[DataProvider('clockChangeWindows')]
    public function testExpiryIsMonotoneAcrossTheClockChange(string $grantedAt, string $from, string $to): void
    {
        $connection = $this->connectionGrantedAt($this->paris($grantedAt));
        $now = new DateTimeImmutable($from)->setTimezone($this->paris);
        $end = new DateTimeImmutable($to);
        $step = new DateInterval('PT15M');
        $expiredAt = null;

        self::assertFalse($connection->isExpiredAt($now, $this->lifetimes), 'Morte avant la fenêtre.');

        for (; $now <= $end; $now = $now->add($step)) {
            $expired = $connection->isExpiredAt($now, $this->lifetimes);

            if (null !== $expiredAt) {
                self::assertTrue($expired, \sprintf('Morte à %s, vivante de nouveau à %s.', $expiredAt, $now->format(DATE_ATOM)));
            } elseif ($expired) {
                $expiredAt = $now->format(DATE_ATOM);
            }
        }

        self::assertNotNull($expiredAt, 'Toujours vivante après la fenêtre.');
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
