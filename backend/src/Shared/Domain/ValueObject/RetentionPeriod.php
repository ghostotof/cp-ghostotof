<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\InvalidRetentionPeriodException;

/**
 * Durée de rétention strictement positive, exprimée en intervalle relatif PHP
 * (ex. "30 days", "12 hours") — utilisée par les commandes de purge pour
 * calculer un seuil d'ancienneté sûr.
 *
 * Piège corrigé (issue #248) : la commande de purge des messages de contact en
 * échec construisait auparavant `new \DateTimeImmutable('-'.$olderThan)`. Avec
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
 * `\DateInterval::createFromDateString()` est utilisé plutôt que
 * `\DateTimeImmutable` : depuis PHP 8.3 il lève `\DateMalformedIntervalStringException`
 * sur une expression illisible (vérifié empiriquement en PHP 8.5 : une chaîne
 * comme "not-an-interval" lève directement, elle ne produit jamais
 * silencieusement un intervalle nul) ; si une version plus ancienne du moteur
 * en produisait malgré tout un, la garde ci-dessus le refuserait de toute
 * façon puisqu'un intervalle nul donne un seuil égal à `$now`.
 */
final readonly class RetentionPeriod
{
    private function __construct(
        private \DateTimeImmutable $threshold,
        private string $expression,
    ) {
    }

    /**
     * @throws InvalidRetentionPeriodException si l'expression est vide, illisible,
     *         ou ne produit pas un seuil strictement antérieur à `$now`
     */
    public static function fromString(string $expression, \DateTimeImmutable $now): self
    {
        $trimmed = trim($expression);

        if ('' === $trimmed) {
            throw InvalidRetentionPeriodException::unreadable($expression);
        }

        try {
            $interval = \DateInterval::createFromDateString($trimmed);
        } catch (\DateMalformedIntervalStringException) {
            throw InvalidRetentionPeriodException::unreadable($expression);
        }

        $threshold = $now->sub($interval);

        if ($threshold >= $now) {
            throw InvalidRetentionPeriodException::notStrictlyPositive($expression);
        }

        return new self($threshold, $trimmed);
    }

    public function threshold(): \DateTimeImmutable
    {
        return $this->threshold;
    }

    public function expression(): string
    {
        return $this->expression;
    }
}
