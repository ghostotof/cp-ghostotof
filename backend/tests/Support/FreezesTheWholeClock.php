<?php

declare(strict_types=1);

namespace App\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\After;
use Symfony\Bridge\PhpUnit\ClockMock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Fige **toutes** les horloges que lit un `Retry-After`, sur un même instant
 * (issue #743).
 *
 * Il y en a deux, et `mockTime()` n'en fige qu'une. Les écouteurs du projet
 * (`RetryAfterListener`, `LoginThrottlingRefusalListener`) lisent
 * `Psr\Clock\ClockInterface`, que `mockTime()` fige. Le limiteur de Symfony
 * (`microtime(true)` dans `Symfony\Component\RateLimiter\Policy`) et
 * `LoginThrottlingListener` (`time()`) lisent l'heure **réelle**, sans horloge
 * injectable : l'échéance qu'ils posent, et le `Retry-After` qui s'en déduit,
 * dépendaient alors de la fraction de seconde où le test s'exécutait.
 *
 * `ClockMock` (symfony/phpunit-bridge) remplace `time()`/`microtime()`… dans les
 * seuls espaces de noms enregistrés par `tests/bootstrap.php`, et seulement le
 * temps d'un test : {@see self::releaseTheNativeClock()} le relâche à la fin.
 *
 * **La fraction de seconde est voulue** : `.999` est le pire cas, la fenêtre
 * s'ouvre et le refus tombe dans la même seconde réelle, à un millième de la
 * seconde suivante. Un calcul qui arrondit par excès y gagne une seconde, et
 * donc une minute entière sur le délai d'un login.
 */
trait FreezesTheWholeClock
{
    use ClockSensitiveTrait;

    /** UTC, une fraction de seconde au plus près de la suivante. */
    protected const string FROZEN_INSTANT = '2026-10-10 12:00:00.999';

    /**
     * Fige `ClockInterface` et `ClockMock` sur le même instant.
     */
    protected static function freezeTheWholeClock(string $instant = self::FROZEN_INSTANT): DateTimeImmutable
    {
        $at = new DateTimeImmutable($instant, new DateTimeZone('UTC'));

        self::mockTime($at);
        ClockMock::withClockMock((float) $at->format('U.u'));

        return $at;
    }

    /**
     * Rend l'horloge native : `ClockMock` est global au processus, un test qui
     * ne le relâcherait pas figerait ceux qui le suivent.
     */
    #[After]
    protected static function releaseTheNativeClock(): void
    {
        ClockMock::withClockMock(false);
    }
}
