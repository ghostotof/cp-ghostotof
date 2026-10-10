<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * Une exception de dépassement de quota qui sait quand le client pourra
 * réessayer. App\Shared\Infrastructure\Http\RetryAfterListener en tire
 * l'en-tête HTTP `Retry-After`, quelle que soit la route ou l'écouteur qui
 * construit la réponse 429 (issue #273).
 *
 * Propriété d'interface (PHP 8.4) plutôt que méthode : les exceptions la
 * satisfont par leur `public readonly \DateTimeImmutable $retryAfter` promu,
 * sans accesseur à écrire, et le nom lu par leurs appelants ne change pas.
 * Le domaine n'en dépend d'aucun composant HTTP : une échéance est un fait
 * métier, l'en-tête en est la traduction, faite en infrastructure.
 *
 * Une nouvelle exception de quota doit aussi être tracée : triée par
 * ThrottledRequestAuditListener si c'est un quota par IP de route anonyme,
 * justifiée comme quota par compte sinon. ThrottledRequestAuditCoverageTest
 * rougit tant qu'elle n'est ni l'une ni l'autre (issue #361).
 */
interface RetryAfterAware
{
    public \DateTimeImmutable $retryAfter { get; }
}
