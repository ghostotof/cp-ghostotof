<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Domain\ValueObject;

use App\Mcp\Connection\Domain\Exception\InvalidConnectionLifetimeException;
use App\Mcp\Connection\Domain\ValueObject\ConnectionLifetimes;
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
        'authorizationCode' => 'PT10M',
        'accessToken' => 'PT1H',
        'refreshToken' => 'P30D',
        'idle' => 'P30D',
        'maximum' => 'P90D',
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
        self::assertSame('90 0 0', $this->lifetimes(['idle' => 'P90D', 'maximum' => 'P90D'])->idle()->format('%d %h %i'));

        $this->expectException(InvalidConnectionLifetimeException::class);
        $this->expectExceptionMessage(InvalidConnectionLifetimeException::idleExceedsMaximum()->getMessage());

        $this->lifetimes(['idle' => 'P91D', 'maximum' => 'P90D']);
    }

    /** Un jeton d'accès qui survivrait à son rafraîchissement n'aurait plus de quoi se renouveler. */
    public function testAnAccessTokenOutlivingItsRefreshTokenIsRefused(): void
    {
        $this->expectException(InvalidConnectionLifetimeException::class);
        $this->expectExceptionMessage(InvalidConnectionLifetimeException::accessTokenOutlivesRefreshToken()->getMessage());

        $this->lifetimes(['accessToken' => 'P31D', 'refreshToken' => 'P30D']);
    }

    /**
     * Revue n° 4 : 30 jours de calendrier à Paris, comptés à travers le
     * passage à l'heure d'hiver (2026-10-25), durent 721 heures. Le seuil
     * tombe à la même heure murale, ni une heure avant ni une heure après —
     * de même pour la durée maximale, 90 jours en arrière.
     */
    public function testThresholdsCountCalendarDaysAcrossTheAutumnClockChange(): void
    {
        $paris = new DateTimeZone('Europe/Paris');
        $now = new DateTimeImmutable('2026-10-26 10:00:00', $paris);

        $idleThreshold = $this->lifetimes()->idleThreshold($now);
        $maximumThreshold = $this->lifetimes()->maximumThreshold($now);

        self::assertEquals(new DateTimeImmutable('2026-09-26 10:00:00', $paris), $idleThreshold);
        self::assertSame(721 * 3600, $now->getTimestamp() - $idleThreshold->getTimestamp());
        self::assertEquals(new DateTimeImmutable('2026-07-28 10:00:00', $paris), $maximumThreshold);
        self::assertSame((90 * 24 + 1) * 3600, $now->getTimestamp() - $maximumThreshold->getTimestamp());
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
            $values['authorizationCode'],
            $values['accessToken'],
            $values['refreshToken'],
            $values['idle'],
            $values['maximum'],
        );
    }
}
