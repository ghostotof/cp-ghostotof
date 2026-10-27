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
 * strictement positif, l'inactivité et la durée maximale dépassent un jour plus
 * la durée d'un code, une heure et une minute, l'inactivité ne dépasse pas la durée maximale, le jeton
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
     * Les deux planchers du délai d'inactivité et de la durée maximale d'une
     * connexion, jugés paramètre par paramètre, l'inactivité d'abord, chacun
     * avant l'ordre des deux durées.
     *
     * Sous un jour (relecture du lot B de #649) : la purge présélectionne ses
     * candidates par `maintenant − durée + P1D`, et une durée plus courte
     * mettrait cette borne dans le futur — chaque connexion active serait
     * verrouillée `FOR UPDATE` à chaque nuit. Une seconde de moins qu'un jour
     * reste refusée pour cette cause.
     *
     * Jusqu'à un jour plus la durée d'un code plus une heure plus une minute,
     * inclus (#726, CR1) : la connexion d'un code encore échangeable pourrait
     * être candidate de la purge, et le marquage (`FOR UPDATE … ORDER BY id`)
     * croiserait le remplacement de l'échange, qui tient cette connexion la
     * première. L'heure couvre les changements d'heure (le jour de calendrier
     * de la borne de la purge dure alors 23 ou 25 heures, voir
     * testAFreshConnectionIsNeverPreselectedAcrossTheClockChanges()), la
     * minute la troncature à la seconde, l'écart entre l'octroi et le code et
     * le décalage d'horloge entre nœuds. Avec un code de `PT10M`, `P1D`,
     * `P1DT1H10M1S` et `P1DT1H11M` sont refusés, une seconde de plus est
     * admise. La borne suit la durée configurée du code.
     *
     * @return iterable<string, array{array<string, string>, ?string, ?string}>
     */
    public static function connectionDurationsAroundTheFloors(): iterable
    {
        yield 'inactivité de douze heures' => [['idleLifetime' => 'PT12H'], 'idleLifetime', 'belowOneDay'];
        yield 'inactivité d\'une seconde sous le jour' => [['idleLifetime' => 'PT23H59M59S'], 'idleLifetime', 'belowOneDay'];
        yield 'inactivité d\'un jour' => [['idleLifetime' => 'P1D'], 'idleLifetime', 'freshConnection'];
        yield 'inactivité de vingt-quatre heures' => [['idleLifetime' => 'PT24H'], 'idleLifetime', 'freshConnection'];
        yield 'inactivité d\'un jour, d\'un code et d\'une seconde' => [['idleLifetime' => 'P1DT10M1S'], 'idleLifetime', 'freshConnection'];
        yield 'inactivité d\'un jour, d\'un code, d\'une heure et d\'une seconde' => [['idleLifetime' => 'P1DT1H10M1S'], 'idleLifetime', 'freshConnection'];
        yield 'inactivité au plancher' => [['idleLifetime' => 'P1DT1H11M'], 'idleLifetime', 'freshConnection'];
        yield 'inactivité au plancher, en heures' => [['idleLifetime' => 'PT25H11M'], 'idleLifetime', 'freshConnection'];
        yield 'inactivité d\'une seconde au-delà du plancher' => [['idleLifetime' => 'P1DT1H11M1S'], null, null];
        yield 'inactivité d\'une seconde au-delà du plancher, en heures' => [['idleLifetime' => 'PT25H11M1S'], null, null];
        yield 'durée maximale de douze heures' => [['idleLifetime' => 'PT12H', 'maximumLifetime' => 'PT12H'], 'idleLifetime', 'belowOneDay'];
        yield 'durée maximale d\'une seconde sous le jour' => [['idleLifetime' => 'P1DT1H11M1S', 'maximumLifetime' => 'PT23H59M59S'], 'maximumLifetime', 'belowOneDay'];
        yield 'durée maximale d\'un jour' => [['idleLifetime' => 'P1DT1H11M1S', 'maximumLifetime' => 'P1D'], 'maximumLifetime', 'freshConnection'];
        yield 'durée maximale au plancher' => [['idleLifetime' => 'P1DT1H11M1S', 'maximumLifetime' => 'P1DT1H11M'], 'maximumLifetime', 'freshConnection'];
        yield 'durée maximale d\'une seconde au-delà du plancher' => [['idleLifetime' => 'P1DT1H11M1S', 'maximumLifetime' => 'P1DT1H11M1S'], null, null];
        yield 'inactivité d\'un jour sous une durée maximale admise' => [['idleLifetime' => 'P1D', 'maximumLifetime' => 'P1DT1H11M1S'], 'idleLifetime', 'freshConnection'];
        yield 'code d\'une heure, inactivité d\'un jour, une heure et onze minutes' => [['codeLifetime' => 'PT1H', 'idleLifetime' => 'P1DT1H11M1S'], 'idleLifetime', 'freshConnection'];
        yield 'code d\'une heure, inactivité au plancher' => [['codeLifetime' => 'PT1H', 'idleLifetime' => 'P1DT2H1M'], 'idleLifetime', 'freshConnection'];
        yield 'code d\'une heure, inactivité d\'une seconde au-delà du plancher' => [['codeLifetime' => 'PT1H', 'idleLifetime' => 'P1DT2H1M1S'], null, null];
        yield 'code d\'une heure, durée maximale au plancher' => [['codeLifetime' => 'PT1H', 'idleLifetime' => 'P1DT2H1M1S', 'maximumLifetime' => 'P1DT2H1M'], 'maximumLifetime', 'freshConnection'];
    }

    /**
     * Le plancher ferme le croisement par construction, changements d'heure
     * compris (#726, CR1) : à la plus courte durée admise, aucune connexion
     * dont le code est encore échangeable n'est présélectionnée par la purge,
     * à aucune seconde autour des deux changements d'heure de 2026 à Paris,
     * une minute de jeu laissée à son octroi.
     *
     * La borne est celle de la purge, écrite comme elle (`$now->sub(durée)
     * ->add(P1D)`, {@see \App\Mcp\Connection\Application\McpConnectionPurger})
     * et comparée à l'octroi le plus ancien d'un code encore échangeable
     * (`$now − code`, temps écoulé : league refuse un code passé son
     * `expire_time`), avancé d'une minute : l'octroi tronqué à la seconde,
     * l'écart entre l'octroi et le code, le décalage d'horloge entre le nœud
     * du Job et celui de FPM. Témoins : au plancher refusé (`P1DT1H11M`) le
     * jeu est consommé à la seconde près, et sans l'heure (`P1DT10M1S`, la
     * mesure de #726 : 3 599 s de recouvrement) la borne atteint l'octroi.
     */
    public function testAFreshConnectionIsNeverPreselectedAcrossTheClockChanges(): void
    {
        $code = new DateInterval(self::CONFIGURED['codeLifetime']);
        $shortest = $this->lifetimes(['idleLifetime' => 'P1DT1H11M1S', 'maximumLifetime' => 'P1DT1H11M1S']);

        foreach ([$shortest->idle(), $shortest->maximum()] as $duration) {
            self::assertNull($this->firstFreshConnectionPreselected($duration, $code), 'Une connexion fraîche est candidate de la purge.');
        }

        self::assertNotNull($this->firstFreshConnectionPreselected(new DateInterval('P1DT1H11M'), $code), 'Le témoin au plancher n\'est pas atteint : le balayage ne voit rien.');
        self::assertNotNull($this->firstFreshConnectionPreselected(new DateInterval('P1DT10M1S'), $code), 'Le plancher sans l\'heure n\'est plus atteint : le balayage ne voit rien.');
    }

    /**
     * Le premier instant de purge où la borne de présélection (pour `$duration`)
     * atteint l'octroi le plus ancien d'un code encore échangeable, une minute
     * de jeu comprise, ou `null`. Balayage à la seconde de 00:00 à 02:00 UTC
     * les jours où la mesure a trouvé un recouvrement (le 29 et le 30 mars, le
     * 25 et le 26 octobre 2026), à la minute sur les cinq jours autour de
     * chaque changement.
     */
    private function firstFreshConnectionPreselected(DateInterval $duration, DateInterval $code): ?DateTimeImmutable
    {
        $paris = new DateTimeZone('Europe/Paris');
        $margin = new DateInterval(ConnectionLifetimes::MINIMUM_CONNECTION_LIFETIME);
        // La minute de jeu du plancher (troncature, écart octroi-code, décalage
        // d'horloge), privée dans ConnectionLifetimes : écrite ici en clair.
        $slack = new DateInterval('PT1M');
        $sweeps = [];
        foreach (['2026-03-29', '2026-03-30', '2026-10-25', '2026-10-26'] as $day) {
            $sweeps[] = [new DateTimeImmutable($day.'T00:00:00+00:00'), 2 * 3600, 1];
        }

        foreach (['2026-03-27', '2026-10-23'] as $day) {
            $sweeps[] = [new DateTimeImmutable($day.'T00:00:00+00:00'), 5 * 24 * 3600, 60];
        }

        foreach ($sweeps as [$start, $length, $step]) {
            for ($elapsed = 0; $elapsed < $length; $elapsed += $step) {
                $now = $start->add(new DateInterval('PT'.$elapsed.'S'))->setTimezone($paris);
                if ($now->sub($duration)->add($margin) >= $now->sub($code)->sub($slack)) {
                    return $now;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $overrides
     * @param ?string               $refusedParameter le paramètre que le refus nomme ; `null` si la configuration est admise
     * @param ?string               $floor            le plancher franchi : `belowOneDay` ou `freshConnection`
     */
    #[DataProvider('connectionDurationsAroundTheFloors')]
    public function testAConnectionDurationAtOrBelowItsFloorsIsRefused(array $overrides, ?string $refusedParameter, ?string $floor): void
    {
        if (null === $refusedParameter) {
            $lifetimes = $this->lifetimes($overrides);
            $expected = [...self::CONFIGURED, ...$overrides];
            self::assertSame($this->seconds(new DateInterval($expected['idleLifetime'])), $this->seconds($lifetimes->idle()), 'Une durée admise est lue telle quelle.');
            self::assertSame($this->seconds(new DateInterval($expected['maximumLifetime'])), $this->seconds($lifetimes->maximum()), 'Une durée admise est lue telle quelle.');

            return;
        }

        $expectedRefusal = 'belowOneDay' === $floor
            ? InvalidConnectionLifetimeException::belowOneDay($refusedParameter)
            : InvalidConnectionLifetimeException::notBeyondFreshConnectionWindow($refusedParameter);

        try {
            $this->lifetimes($overrides);
            self::fail('Une durée de connexion à son plancher ou en dessous a été acceptée.');
        } catch (InvalidConnectionLifetimeException $refusal) {
            self::assertSame($expectedRefusal->getMessage(), $refusal->getMessage());
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
