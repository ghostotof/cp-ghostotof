<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Domain\ValueObject;

use App\Mcp\Connection\Domain\Exception\InvalidConnectionLifetimeException;
use App\Mcp\Connection\Domain\ValueObject\ConnectionLifetimes;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Les durées d'une connexion MCP et de ses jetons (#649), lues en
 * configuration : chacune doit être un intervalle ISO 8601 lisible et
 * strictement positif, l'inactivité ne dépasse pas la durée maximale, le jeton
 * d'accès ne survit pas à son rafraîchissement. Un refus nomme le paramètre,
 * jamais la valeur.
 */
final class ConnectionLifetimesTest extends TestCase
{
    /** Les valeurs de `config/services.yaml`, dans l'ordre de fromConfiguration(). */
    private const array CONFIGURED = [
        'codeLifetime' => 'PT10M',
        'accessLifetime' => 'PT1H',
        'refreshLifetime' => 'P30D',
        'idleLifetime' => 'P30D',
        'maximumLifetime' => 'P90D',
    ];

    public function testTheConfiguredValuesAreRead(): void
    {
        $lifetimes = $this->lifetimes();

        self::assertSame('0 0 10', $lifetimes->authorizationCode()->format('%d %h %i'));
        self::assertSame('0 1 0', $lifetimes->accessToken()->format('%d %h %i'));
        self::assertSame('30 0 0', $lifetimes->refreshToken()->format('%d %h %i'));
        self::assertSame('30 0 0', $lifetimes->idle()->format('%d %h %i'));
        self::assertSame('90 0 0', $lifetimes->maximum()->format('%d %h %i'));

        // `DateInterval` est mutable : chaque lecture rend une copie, qu'un
        // appelant peut modifier sans toucher la suivante.
        $copy = $lifetimes->idle();
        $copy->d = 1;
        self::assertSame('30 0 0', $lifetimes->idle()->format('%d %h %i'));
    }

    /**
     * Chaque valeur illisible, sur chacun des cinq paramètres.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function unreadableDurations(): iterable
    {
        foreach (array_keys(self::CONFIGURED) as $parameter) {
            foreach (['P', '30 days', ''] as $value) {
                yield $parameter.', « '.$value.' »' => [$parameter, $value];
            }
        }
    }

    #[DataProvider('unreadableDurations')]
    public function testAnUnreadableDurationIsRefusedNamingItsParameter(string $parameter, string $value): void
    {
        try {
            $this->lifetimes([$parameter => $value]);
            self::fail('Une durée illisible a été acceptée.');
        } catch (InvalidConnectionLifetimeException $refusal) {
            self::assertSame(InvalidConnectionLifetimeException::unreadable($parameter)->getMessage(), $refusal->getMessage());
            self::assertStringContainsString($parameter, $refusal->getMessage());
            // La chaîne vide est dans tout message : rien à vérifier pour elle,
            // l'égalité avec la fabrique (qui ne reçoit pas la valeur) suffit.
            if ('' !== $value) {
                self::assertStringNotContainsString($value, $refusal->getMessage(), 'Le refus cite la valeur.');
            }
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function zeroDurations(): iterable
    {
        foreach (array_keys(self::CONFIGURED) as $parameter) {
            foreach (['PT0S', 'P0D'] as $value) {
                yield $parameter.', '.$value => [$parameter, $value];
            }
        }
    }

    #[DataProvider('zeroDurations')]
    public function testAZeroDurationIsRefused(string $parameter, string $value): void
    {
        $this->expectException(InvalidConnectionLifetimeException::class);
        $this->expectExceptionMessage(InvalidConnectionLifetimeException::notPositive($parameter)->getMessage());

        $this->lifetimes([$parameter => $value]);
    }

    /**
     * Une connexion inactive ne peut vivre plus longtemps que sa durée
     * maximale : l'égalité est admise, un jour de plus non.
     */
    public function testAnIdleLifetimeBeyondTheMaximumIsRefused(): void
    {
        self::assertSame('90 0 0', $this->lifetimes(['idleLifetime' => 'P90D', 'maximumLifetime' => 'P90D'])->idle()->format('%d %h %i'));

        $this->expectException(InvalidConnectionLifetimeException::class);
        $this->expectExceptionMessage(InvalidConnectionLifetimeException::idleExceedsMaximum()->getMessage());

        $this->lifetimes(['idleLifetime' => 'P91D', 'maximumLifetime' => 'P90D']);
    }

    /** Un jeton d'accès qui survivrait à son rafraîchissement n'aurait plus de quoi se renouveler. */
    public function testAnAccessTokenOutlivingItsRefreshTokenIsRefused(): void
    {
        $this->expectException(InvalidConnectionLifetimeException::class);
        $this->expectExceptionMessage(InvalidConnectionLifetimeException::accessTokenOutlivesRefreshToken()->getMessage());

        $this->lifetimes(['accessLifetime' => 'P31D', 'refreshLifetime' => 'P30D']);
    }

    /**
     * Le délai d'inactivité et la durée maximale d'une connexion valent au
     * moins un jour (relecture du lot B de #649) : la purge présélectionne ses
     * candidates par `maintenant − durée + P1D`, et une durée plus courte
     * mettrait cette borne dans le futur — chaque connexion active serait
     * verrouillée `FOR UPDATE` à chaque nuit. Un jour exactement est admis
     * (`P1D` comme `PT24H`, égaux sur l'instant de référence) ; une seconde de
     * moins est refusée, le plancher jugé avant l'ordre des deux durées.
     *
     * @return iterable<string, array{array<string, string>, ?string}>
     */
    public static function connectionDurationsAroundOneDay(): iterable
    {
        yield 'inactivité d\'un jour' => [['idleLifetime' => 'P1D'], null];
        yield 'inactivité de vingt-quatre heures' => [['idleLifetime' => 'PT24H'], null];
        yield 'inactivité d\'une seconde sous le jour' => [['idleLifetime' => 'PT23H59M59S'], 'idleLifetime'];
        yield 'inactivité de douze heures' => [['idleLifetime' => 'PT12H'], 'idleLifetime'];
        yield 'durée maximale d\'un jour' => [['idleLifetime' => 'P1D', 'maximumLifetime' => 'P1D'], null];
        yield 'durée maximale d\'une seconde sous le jour' => [['idleLifetime' => 'P1D', 'maximumLifetime' => 'PT23H59M59S'], 'maximumLifetime'];
        yield 'durée maximale de douze heures' => [['idleLifetime' => 'PT12H', 'maximumLifetime' => 'PT12H'], 'idleLifetime'];
    }

    /**
     * @param array<string, string> $overrides
     * @param ?string               $refusedParameter le paramètre que le refus nomme ; `null` si la configuration est admise
     */
    #[DataProvider('connectionDurationsAroundOneDay')]
    public function testAConnectionDurationUnderOneDayIsRefused(array $overrides, ?string $refusedParameter): void
    {
        if (null === $refusedParameter) {
            $lifetimes = $this->lifetimes($overrides);
            self::assertSame(24 * 3600, $this->seconds($lifetimes->idle()), 'Une durée d\'un jour est admise, lue telle quelle.');

            return;
        }

        try {
            $this->lifetimes($overrides);
            self::fail('Une durée de connexion sous un jour a été acceptée.');
        } catch (InvalidConnectionLifetimeException $refusal) {
            self::assertSame(InvalidConnectionLifetimeException::belowOneDay($refusedParameter)->getMessage(), $refusal->getMessage());
            self::assertStringContainsString($refusedParameter, $refusal->getMessage());
            self::assertStringNotContainsString($overrides[$refusedParameter], $refusal->getMessage(), 'Le refus cite la valeur.');
        }
    }

    /** Les secondes qu'un intervalle ajoute à l'instant de référence (hors changement d'heure : UTC). */
    private function seconds(DateInterval $interval): int
    {
        $reference = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        return $reference->add($interval)->getTimestamp() - $reference->getTimestamp();
    }

    /**
     * Revue n° 4 : 30 jours de calendrier à Paris, comptés à travers le
     * passage à l'heure d'hiver (2026-10-25), durent 721 heures. L'échéance
     * tombe à la même heure murale, ni une heure avant ni une heure après —
     * de même pour la durée maximale, 90 jours après l'octroi.
     */
    public function testDeadlinesCountCalendarDaysAcrossTheAutumnClockChange(): void
    {
        $paris = new DateTimeZone('Europe/Paris');
        $lastActivity = new DateTimeImmutable('2026-09-26 10:00:00', $paris);
        $grantedAt = new DateTimeImmutable('2026-07-28 10:00:00', $paris);

        $idleDeadline = $this->lifetimes()->idleDeadline($lastActivity);
        $maximumDeadline = $this->lifetimes()->maximumDeadline($grantedAt);

        self::assertEquals(new DateTimeImmutable('2026-10-26 10:00:00', $paris), $idleDeadline);
        self::assertSame(721 * 3600, $idleDeadline->getTimestamp() - $lastActivity->getTimestamp());
        self::assertEquals(new DateTimeImmutable('2026-10-26 10:00:00', $paris), $maximumDeadline);
        self::assertSame((90 * 24 + 1) * 3600, $maximumDeadline->getTimestamp() - $grantedAt->getTimestamp());
    }

    /**
     * Une heure murale qui n'existe pas (02:30 le 29 mars 2026, passage à
     * l'heure d'été) : PHP reporte l'échéance d'une heure, à 03:30 CEST — la
     * connexion vit une heure de plus, jamais de moins, et l'échéance reste
     * un instant fixe.
     */
    public function testADeadlineOnAMissingWallTimeIsPushedPastTheGap(): void
    {
        $paris = new DateTimeZone('Europe/Paris');

        $deadline = $this->lifetimes()->idleDeadline(new DateTimeImmutable('2026-02-27 02:30:00', $paris));

        self::assertSame('2026-03-29T03:30:00+02:00', $deadline->format(DATE_ATOM));
    }

    /**
     * Les durées de la configuration, `$overrides` remplaçant certaines par leur nom.
     *
     * @param array<string, string> $overrides
     */
    private function lifetimes(array $overrides = []): ConnectionLifetimes
    {
        $values = [...self::CONFIGURED, ...$overrides];

        return ConnectionLifetimes::fromConfiguration(
            $values['codeLifetime'],
            $values['accessLifetime'],
            $values['refreshLifetime'],
            $values['idleLifetime'],
            $values['maximumLifetime'],
        );
    }
}
