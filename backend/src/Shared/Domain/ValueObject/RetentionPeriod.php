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
 * Les jours, mois et années se comptent sur le calendrier du fuseau de `$now`,
 * les heures, minutes et secondes en temps écoulé (règles de #445, issue #710).
 * Toute expression relative PHP n'est pas une durée : sont refusées celles qui
 * désignent une date ("monday", "first day of next month", "yesterday"), qui
 * mêlent des termes de signes opposés, dont PHP lit mal un nombre, ou qui
 * placent le seuil avant l'an 1 (relecture de #710).
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
    /**
     * Le plancher d'une purge qui l'exige ({@see thresholdAtLeastOneDayBefore()}) :
     * un jour du calendrier du fuseau de l'instant donné.
     */
    private const string PURGE_FLOOR = 'P1D';

    /** Voir {@see designatesADate()} : un mercredi en milieu de mois, hors de minuit. */
    private const string REFERENCE_INSTANT = '1970-01-14 13:37:42';

    /**
     * Un nombre de 14 chiffres ou plus (relecture de #710). PHP lit exactement
     * jusqu'à 13 chiffres et toujours de travers au-delà, mesuré sous PHP 8.5 :
     * « 98765432109876 seconds » donne 5432109876 secondes, acceptées en
     * silence, « 9223372036854775807 seconds » 36854775807. Treize chiffres de
     * secondes font déjà 316 000 ans, que la borne de l'an 1 refuse.
     */
    private const string NUMBER_PHP_MISREADS = '/\d{14,}/';

    private function __construct(
        private DateTimeImmutable $threshold,
        private DateInterval $interval,
        private string $expression,
    ) {
    }

    /**
     * @throws InvalidRetentionPeriodException si l'expression est vide, illisible,
     *         désigne une date plutôt qu'une durée, ne produit pas un seuil
     *         strictement antérieur à `$now`, ou un seuil antérieur à l'an 1
     */
    public static function fromString(string $expression, DateTimeImmutable $now): self
    {
        $trimmed = trim($expression);
        $interval = self::durationOf($trimmed, $expression);
        $threshold = $now->sub($interval);

        if ($threshold >= $now) {
            throw InvalidRetentionPeriodException::notStrictlyPositive($expression);
        }

        // Avant l'an 1, le seuil s'écrit « -2974-… » ou « 0000-… », que
        // PostgreSQL refuse (relecture de #710). Jugé en UTC, l'heure murale
        // où les commandes de purge le comparent.
        if ((int) $threshold->setTimezone(new DateTimeZone('UTC'))->format('Y') < 1) {
            throw InvalidRetentionPeriodException::tooLong($expression);
        }

        return new self($threshold, $interval, $trimmed);
    }

    /**
     * Lit l'expression en un intervalle qui suit les règles de #445, ou la refuse.
     *
     * @throws InvalidRetentionPeriodException si l'expression est vide, illisible
     *         ou désigne une date
     */
    private static function durationOf(string $trimmed, string $expression): DateInterval
    {
        if ('' === $trimmed || 1 === preg_match(self::NUMBER_PHP_MISREADS, $trimmed)) {
            throw InvalidRetentionPeriodException::unreadable($expression);
        }

        try {
            $relative = DateInterval::createFromDateString($trimmed);
        } catch (DateMalformedIntervalStringException) {
            throw InvalidRetentionPeriodException::unreadable($expression);
        }

        if (self::mixesSigns($relative)) {
            throw InvalidRetentionPeriodException::mixesSigns($expression);
        }

        $interval = self::calendarDaysThenElapsedTime($relative);

        if (self::designatesADate($trimmed, $interval)) {
            throw InvalidRetentionPeriodException::designatesADate($expression);
        }

        return $interval;
    }

    /**
     * Recopie les champs d'un intervalle lu par {@see DateInterval::createFromDateString()}
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
     * Vrai si des champs de l'intervalle ont des signes opposés (« 1 day -23
     * hours »), relecture de #710. Les jours se comptent sur le calendrier, les
     * heures en temps écoulé : leur différence varie avec les changements
     * d'heure, et l'option changerait de sens selon le jour (1 heure, 2, ou 0).
     */
    private static function mixesSigns(DateInterval $relative): bool
    {
        $fields = [$relative->y, $relative->m, $relative->d, $relative->h, $relative->i, $relative->s, $relative->f];

        return min($fields) < 0 && max($fields) > 0;
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
     * Ces parts ne sont visibles dans aucun champ de {@see DateInterval}, et
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
     * semaine ramène à minuit, celui de la référence compris). Une heure
     * explicite ("wednesday 13:37:42") ne peut pas tomber sur la référence :
     * {@see DateInterval::createFromDateString()} la refuse avant, illisible.
     * La comparaison reste une heuristique : sans écart sur le catalogue des
     * tests, elle n'est pas prouvée sur toute la grammaire relative de PHP.
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

    /**
     * La durée elle-même, pour un appelant qui la retranche à son propre
     * instant (`PendingInvitationPurger`, relecture de #710) : un intervalle
     * construit, qui compte les jours sur le calendrier et les heures en temps
     * écoulé. Une copie, `DateInterval` étant mutable.
     */
    public function interval(): DateInterval
    {
        return clone $this->interval;
    }

    public function expression(): string
    {
        return $this->expression;
    }

    /**
     * Le seuil de cette durée retranchée à `$now`, à condition qu'il tombe au
     * plus tard « `$now` moins un jour » : le plancher des purges des
     * invitations jamais activées et des connexions MCP révoquées (lot C des
     * relectures de #649, écrit une fois ici au lieu d'une constante et d'une
     * exception par purge). Sans lui, un lancement manuel imprudent
     * (`--older-than "1 hour"`) supprimerait d'un coup ce que la rétention
     * doit garder ; le CronJob n'utilise que la valeur par défaut.
     *
     * L'instant est celui de l'appelant (l'horloge du purgeur), pas celui de
     * {@see fromString()}. Le jour est un jour du calendrier de son fuseau,
     * comme l'écrivaient les deux purges : 25 heures le jour du passage à
     * l'heure d'hiver, 23 celui de l'heure d'été. La purge du formulaire de
     * contact n'a pas de plancher et ne l'appelle pas.
     *
     * @throws InvalidRetentionPeriodException si la durée est plus courte qu'un jour
     */
    public function thresholdAtLeastOneDayBefore(DateTimeImmutable $now): DateTimeImmutable
    {
        $threshold = $now->sub($this->interval);

        if ($threshold > $now->sub(new DateInterval(self::PURGE_FLOOR))) {
            throw InvalidRetentionPeriodException::shorterThanOneDay($this->expression);
        }

        return $threshold;
    }
}
