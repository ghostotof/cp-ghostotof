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
        $this->lifetimes = ConnectionLifetimes::fromConfiguration('PT10M', 'PT1H', 'P30D', 'P30D', 'P90D', 'PT1H');
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
        $lifetimes = ConnectionLifetimes::fromConfiguration('PT10M', 'PT1H', 'P30D', 'P90D', 'P90D', 'PT1H');
        $connection = $this->connectionGrantedAt($this->paris('2026-07-28 10:00:00'));
        self::assertTrue($connection->recordUseAt($this->paris('2026-10-26 09:00:00')));

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

    /**
     * Le dernier usage s'écrit au plus une fois par **heure écoulée**
     * (`McpConnection::recordUseAt()`, #649) : nul, il s'écrit ; cinquante-neuf
     * minutes plus tard, non ; soixante, oui. Jugé à cheval sur l'heure
     * répétée du passage à l'heure d'hiver, où les deux instants d'une heure
     * d'écart affichent la même heure murale, 02:30 : une heure comptée sur
     * la pendule aurait vu zéro minute, ou deux heures.
     */
    public function testTheLastUseIsWrittenAtMostOncePerElapsedHour(): void
    {
        $connection = $this->connectionGrantedAt($this->paris('2026-10-25 01:00:00'));
        $firstUse = new DateTimeImmutable('2026-10-25T00:30:00Z')->setTimezone($this->paris);
        $fiftyNineMinutesLater = $firstUse->add(new DateInterval('PT59M'));
        $oneHourLater = $firstUse->add(new DateInterval('PT1H'));
        self::assertSame('02:30 CEST', $firstUse->format('H:i T'));
        self::assertSame('02:30 CET', $oneHourLater->format('H:i T'));
        self::assertNull($this->lastUseOf($connection));

        self::assertTrue($connection->recordUseAt($firstUse));
        self::assertSame($firstUse->getTimestamp(), $this->lastUseOf($connection));

        self::assertFalse($connection->recordUseAt($fiftyNineMinutesLater));
        self::assertSame($firstUse->getTimestamp(), $this->lastUseOf($connection), 'Un usage de moins d\'une heure ne déplace pas le précédent.');

        self::assertTrue($connection->recordUseAt($oneHourLater));
        self::assertSame($oneHourLater->getTimestamp(), $this->lastUseOf($connection));
    }

    /**
     * L'échéance que « Mes connexions MCP » affiche (#650) est la plus proche
     * des deux : octroyée le 28 juillet, utilisée le 20 octobre, la durée
     * maximale (26 octobre, 90 jours après l'octroi) l'emporte sur
     * l'inactivité (19 novembre) ; jamais utilisée et octroyée le 26 septembre,
     * l'inactivité (26 octobre) l'emporte sur la durée maximale (25 décembre).
     * Rendue dans le fuseau demandé.
     */
    public function testTheDeadlineIsTheEarliestOfIdleAndMaximum(): void
    {
        $recentlyUsed = $this->connectionGrantedAt($this->paris('2026-07-28 10:00:00'));
        self::assertTrue($recentlyUsed->recordUseAt($this->paris('2026-10-20 10:00:00')));
        $neverUsed = $this->connectionGrantedAt($this->paris('2026-09-26 10:00:00'));

        $maximum = $recentlyUsed->deadline($this->paris, $this->lifetimes);
        $idle = $neverUsed->deadline($this->paris, $this->lifetimes);

        self::assertSame('2026-10-26 10:00:00 Europe/Paris', $maximum->format('Y-m-d H:i:s e'));
        self::assertSame('2026-10-26 10:00:00 Europe/Paris', $idle->format('Y-m-d H:i:s e'));
        // Un octroi lu en base (décalage fixe +02:00), utilisé il y a peu : la
        // durée maximale, comptée en jours du calendrier de la zone demandée.
        $hydrated = $this->connectionGrantedAt(new DateTimeImmutable('2026-07-27 10:00:00+02:00'));
        self::assertTrue($hydrated->recordUseAt($this->paris('2026-10-20 10:00:00')));
        self::assertSame('2026-10-25 08:00:00 UTC', $hydrated->deadline(new DateTimeZone('UTC'), $this->lifetimes)->format('Y-m-d H:i:s e'));
        self::assertSame('2026-10-25 10:00:00 Europe/Paris', $hydrated->deadline($this->paris, $this->lifetimes)->format('Y-m-d H:i:s e'));
    }

    /**
     * Les deux définitions ne se séparent jamais : à la seconde de
     * {@see McpConnection::deadline()} la connexion est expirée, la seconde
     * d'avant elle ne l'est pas — dans les fenêtres des deux changements
     * d'heure, heure répétée de l'automne et heure sautée du printemps.
     */
    #[DataProvider('clockChangeWindows')]
    public function testIsExpiredAtAgreesWithTheDeadlineSecondBySecond(string $grantedAt, string $from, string $to): void
    {
        unset($from, $to);
        $connection = $this->connectionGrantedAt($this->paris($grantedAt));
        $deadline = $connection->deadline($this->paris, $this->lifetimes);
        $oneSecond = new DateInterval('PT1S');

        self::assertTrue($connection->isExpiredAt($deadline, $this->lifetimes), 'À la seconde de l\'échéance.');
        self::assertFalse($connection->isExpiredAt($deadline->sub($oneSecond), $this->lifetimes), 'La seconde d\'avant.');
        self::assertTrue($connection->isExpiredAt($deadline->add($oneSecond), $this->lifetimes), 'La seconde d\'après.');
    }

    /**
     * Une connexion dont le code n'a jamais été échangé (#730) expire
     * `connection_unexchanged` (une heure) après son octroi — avant l'inactivité
     * (30 jours) et la durée maximale (90) : c'est la plus proche des trois.
     * À la seconde de l'échéance elle est morte, la seconde d'avant vivante ; et
     * la plus proche des échéances est rendue pour l'affichage.
     */
    public function testANeverExchangedConnectionExpiresAtItsUnexchangedDeadline(): void
    {
        $grantedAt = $this->paris('2026-09-26 10:00:00');
        $connection = $this->connectionNeverExchangedGrantedAt($grantedAt);

        $deadline = $connection->deadline($this->paris, $this->lifetimes);

        self::assertSame('2026-09-26 11:00:00 Europe/Paris', $deadline->format('Y-m-d H:i:s e'));
        self::assertFalse($connection->isExpiredAt($this->paris('2026-09-26 10:59:59'), $this->lifetimes));
        self::assertTrue($connection->isExpiredAt($this->paris('2026-09-26 11:00:00'), $this->lifetimes));
    }

    /**
     * Le premier échange du code écrit `code_exchanged_at` (une seule fois :
     * un second appel ne le déplace pas) et supprime l'échéance : la même
     * connexion, une heure et une seconde après son octroi, n'est pas
     * expirée, et son échéance affichée est celle de l'inactivité.
     */
    public function testAnExchangedConnectionDoesNotKnowTheUnexchangedDeadline(): void
    {
        $connection = $this->connectionNeverExchangedGrantedAt($this->paris('2026-09-26 10:00:00'));
        self::assertNull($connection->codeExchangedAt());

        $connection->recordCodeExchangeAt($this->paris('2026-09-26 10:03:00'));
        $connection->recordCodeExchangeAt($this->paris('2026-09-26 10:30:00'));

        self::assertSame($this->paris('2026-09-26 10:03:00')->getTimestamp(), $connection->codeExchangedAt()?->getTimestamp(), 'Le second appel a déplacé l\'instant du premier échange.');
        self::assertFalse($connection->isExpiredAt($this->paris('2026-09-26 11:00:01'), $this->lifetimes));
        self::assertSame('2026-10-26 10:00:00 Europe/Paris', $connection->deadline($this->paris, $this->lifetimes)->format('Y-m-d H:i:s e'));
    }

    /**
     * Les deux définitions ne se séparent jamais pour les non échangées non
     * plus, et l'expiration est monotone, à la seconde, autour du passage à
     * l'heure d'hiver (l'heure de 02:00 à 03:00 se répète) et d'été : une heure
     * de temps écoulé, jamais une heure de pendule.
     */
    #[DataProvider('unexchangedGrantsAroundTheClockChanges')]
    public function testTheUnexchangedDeadlineIsMonotoneSecondBySecond(string $grantedAt): void
    {
        $granted = new DateTimeImmutable($grantedAt)->setTimezone($this->paris);
        $connection = $this->connectionNeverExchangedGrantedAt($granted);
        $deadline = $connection->deadline($this->paris, $this->lifetimes);
        $oneSecond = new DateInterval('PT1S');

        self::assertSame(3600, $deadline->getTimestamp() - $granted->getTimestamp(), 'Une heure écoulée exactement.');
        self::assertTrue($connection->isExpiredAt($deadline, $this->lifetimes), 'À la seconde de l\'échéance.');
        self::assertFalse($connection->isExpiredAt($deadline->sub($oneSecond), $this->lifetimes), 'La seconde d\'avant.');

        $expired = false;
        for ($now = $granted; $now <= $deadline->add(new DateInterval('PT10M')); $now = $now->add(new DateInterval('PT30S'))) {
            $isExpired = $connection->isExpiredAt($now, $this->lifetimes);
            self::assertFalse($expired && !$isExpired, 'Morte, puis vivante de nouveau à '.$now->format(DATE_ATOM));
            $expired = $isExpired;
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unexchangedGrantsAroundTheClockChanges(): iterable
    {
        yield 'avant l\'heure répétée de l\'automne' => ['2026-10-24T23:50:00Z'];
        yield 'dans l\'heure répétée de l\'automne' => ['2026-10-25T00:30:00Z'];
        yield 'à cheval sur le retour à l\'heure d\'hiver' => ['2026-10-25T00:45:00Z'];
        yield 'avant le saut du printemps' => ['2026-03-29T00:30:00Z'];
        yield 'à cheval sur le saut du printemps' => ['2026-03-29T00:45:00Z'];
    }

    /** L'instant du dernier usage noté (époque Unix), ou `null`. */
    private function lastUseOf(McpConnection $connection): ?int
    {
        return $connection->lastUsedAt()?->getTimestamp();
    }

    /**
     * Une connexion dont le code a été échangé (à l'octroi, à la seconde près) :
     * seules l'inactivité et la durée maximale la jugent. Celle dont le code
     * ne l'a jamais été est {@see self::connectionNeverExchangedGrantedAt()}.
     */
    private function connectionGrantedAt(DateTimeImmutable $grantedAt): McpConnection
    {
        $connection = $this->connectionNeverExchangedGrantedAt($grantedAt);
        $connection->recordCodeExchangeAt($grantedAt);

        return $connection;
    }

    /** Une approbation dont le code n'a jamais été échangé (#730) : la troisième échéance la juge aussi. */
    private function connectionNeverExchangedGrantedAt(DateTimeImmutable $grantedAt): McpConnection
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
