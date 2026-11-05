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
     * UTC, pile sur une seconde : l'autre branche de l'arrondi du limiteur. Une
     * fenêtre de 15 minutes ouverte à cet instant se lit 900 s, sans excès ni
     * plafond ; à {@see self::FROZEN_INSTANT} elle se lit 901 s.
     */
    protected const string FROZEN_INSTANT_ON_THE_SECOND = '2026-10-10 12:00:00.000';

    /**
     * Fige `ClockInterface` et `ClockMock` sur le même instant, par défaut le
     * pire cas {@see self::FROZEN_INSTANT}. Un test dont le résultat dépend de
     * la fraction de seconde l'exécute sur les deux.
     *
     * Vider le pool `cache.rate_limiter` **avant** de figer : un état écrit à
     * l'heure réelle puis relu sous l'heure figée (2026-10-10, dans le passé)
     * ne s'éteint jamais, le temps écoulé y est négatif.
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
