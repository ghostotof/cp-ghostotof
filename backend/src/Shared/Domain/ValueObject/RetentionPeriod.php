<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\InvalidRetentionPeriodException;
use DateInterval;
use DateMalformedIntervalStringException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Durée de rétention strictement positive, exprimée en intervalle relatif PHP
 * (ex. "30 days", "12 hours") — utilisée par les commandes de purge pour
 * calculer un seuil d'ancienneté sûr.
 *
 * Piège corrigé (issue #248) : la commande de purge des messages de contact en
 * échec construisait auparavant `new DateTimeImmutable('-'.$olderThan)`. Avec
 * un `$olderThan` déjà négatif (ex. "-30 days"), la concaténation produit
 * `'--30 days'` — une double négation que PHP accepte silencieusement et
 * interprète comme "+30 days" (vérifié empiriquement en PHP 8.5 : cette forme
 * ne lève jamais). Le seuil se retrouve alors dans le futur et la purge
 * supprime tous les messages, y compris les plus récents. Le même défaut
 * touche "0 days" (seuil = maintenant, `>=`, donc refusé) et toute expression
 * relative déjà tournée vers le futur sans signe apparent, comme "2 days ago"
 * (qui vaut « il y a -2 jours » une fois retranché de `$now`).
 *
 * Interdire un signe négatif dans l'expression ne suffirait pas : "2 days
 * ago" ci-dessus n'en porte aucun et produit pourtant un seuil futur. La seule
 * garde qui couvre les trois formes à la fois est de calculer le seuil
 * (`$now - $interval`) puis de vérifier qu'il est strictement antérieur à
 * `$now`.
 *
 * {@see DateInterval::createFromDateString()} est utilisé plutôt que
 * {@see DateTimeImmutable} : depuis PHP 8.3 il lève {@see DateMalformedIntervalStringException}
 * sur une expression illisible (vérifié empiriquement en PHP 8.5 : une chaîne
 * comme "not-an-interval" lève directement, elle ne produit jamais
 * silencieusement un intervalle nul) ; si une version plus ancienne du moteur
 * en produisait malgré tout un, la garde ci-dessus le refuserait de toute
 * façon puisqu'un intervalle nul donne un seuil égal à `$now`.
 */
final readonly class RetentionPeriod
{
    /** Voir {@see designatesADate()} : un mercredi en milieu de mois, hors de minuit. */
    private const string REFERENCE_INSTANT = '1970-01-14 13:37:42';

    private function __construct(
        private DateTimeImmutable $threshold,
        private string $expression,
    ) {
    }

    /**
     * @throws InvalidRetentionPeriodException si l'expression est vide, illisible,
     *         désigne une date plutôt qu'une durée, ou ne produit pas un seuil
     *         strictement antérieur à `$now`
     */
    public static function fromString(string $expression, DateTimeImmutable $now): self
    {
        $trimmed = trim($expression);

        if ('' === $trimmed) {
            throw InvalidRetentionPeriodException::unreadable($expression);
        }

        try {
            $relative = DateInterval::createFromDateString($trimmed);
        } catch (DateMalformedIntervalStringException) {
            throw InvalidRetentionPeriodException::unreadable($expression);
        }

        $interval = self::calendarDaysThenElapsedTime($relative);

        if (self::designatesADate($trimmed, $interval)) {
            throw InvalidRetentionPeriodException::designatesADate($trimmed);
        }

        $threshold = $now->sub($interval);

        if ($threshold >= $now) {
            throw InvalidRetentionPeriodException::notStrictlyPositive($expression);
        }

        return new self($threshold, $trimmed);
    }

    /**
     * Recopie les champs d'un intervalle lu par {@see \DateInterval::createFromDateString()}
     * dans un intervalle construit, que PHP retranche autrement (issue #710).
     *
     * L'intervalle lu est « relatif » : `sub()` le traite comme `modify()` et
     * compte **tout** en heure murale du fuseau de `$now`, si bien que « 12 hours »
     * durait 13 heures écoulées la nuit du passage à l'heure d'hiver et 11 la
     * nuit du passage à l'heure d'été. L'intervalle construit compte les
     * années, mois et jours sur le calendrier de ce fuseau, puis les heures,
     * minutes et secondes en temps écoulé : les deux règles de #445, dans cet
     * ordre (mesuré sous PHP 8.5 : « 1 day 12 hours » dure 37 heures la veille
     * du passage à l'heure d'hiver, 25 + 12).
     *
     * Les champs sont recopiés un à un, signe compris (« -30 days » donne
     * `d = -30`) : la garde de {@see fromString()} voit donc le même seuil
     * qu'avant pour une expression sans heures.
     */
    private static function calendarDaysThenElapsedTime(DateInterval $relative): DateInterval
    {
        $interval = new DateInterval('PT0S');
        $interval->y = $relative->y;
        $interval->m = $relative->m;
        $interval->d = $relative->d;
        $interval->h = $relative->h;
        $interval->i = $relative->i;
        $interval->s = $relative->s;
        $interval->f = self::middleOfTheMicrosecond($relative->f);
        $interval->invert = $relative->invert;

        return $interval;
    }

    /**
     * La fraction de seconde, ramenée au milieu de sa microseconde.
     *
     * `DateInterval::$f` est un flottant : « 498 usec » y vaut 0.000497999…,
     * et `add()`/`sub()` le tronquent à la microseconde (497), là où `modify()`
     * lit 498. Arrondir à six décimales ne suffit pas, le flottant le plus
     * proche restant parfois en deçà. Viser le milieu de la microseconde
     * laisse une demi-microseconde de marge à la troncature : mesuré sous
     * PHP 8.5 sur 120 000 durées (usec, msec, sec, deux signes), `add()`,
     * `sub()` et `modify()` donnent alors le même instant.
     */
    private static function middleOfTheMicrosecond(float $fraction): float
    {
        $microseconds = (int) round($fraction * 1_000_000);

        if (0 === $microseconds) {
            return 0.0;
        }

        return ($microseconds + ($microseconds > 0 ? 0.5 : -0.5)) / 1_000_000;
    }

    /**
     * Vrai si l'expression porte plus que les champs de son intervalle : un
     * jour de la semaine, un "first/last … of", une heure du jour (issue #710).
     *
     * Ces parts ne sont visibles dans aucun champ de {@see \DateInterval}, et
     * PHP les traite mal en arithmétique d'intervalle : `sub()` lève une erreur
     * native sur un jour de la semaine, `add()` comme `sub()` ignorent "first/last
     * day of". Seul `modify()` interprète l'expression entière. Une durée
     * ordinaire y donne, en UTC (où heure murale et temps écoulé coïncident),
     * exactement l'instant que donne son intervalle ; une expression qui désigne
     * une date s'en écarte (mesuré sous PHP 8.5 sur deux années d'instants :
     * aucun écart pour une durée, "yesterday" excepté, qui ramène à minuit).
     *
     * Un instant de référence fixe, plutôt que `$now`, pour que le verdict ne
     * dépende pas du moment où la commande tourne : `$now` un 1er du mois
     * laisserait passer "first day of next month". Il tombe en milieu de mois
     * (aucun "first/last … of" n'y retombe) et hors de minuit (un jour de la
     * semaine ramène à minuit, celui de la référence compris). Limite acceptée :
     * une expression qui nomme cet instant même ("wednesday 13:37:42") passe.
     */
    private static function designatesADate(string $expression, DateInterval $interval): bool
    {
        $reference = new DateTimeImmutable(self::REFERENCE_INSTANT, new DateTimeZone('UTC'));

        // L'instant à la microseconde : "500 msec" porte des fractions de seconde.
        return $reference->modify($expression)->format('U.u') !== $reference->add($interval)->format('U.u');
    }

    public function threshold(): DateTimeImmutable
    {
        return $this->threshold;
    }

    public function expression(): string
    {
        return $this->expression;
    }
}
