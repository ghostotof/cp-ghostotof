<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Presentation\ApiResource;

/**
 * Le volet « cycles de vie » de la veille, avec sa propre fraîcheur.
 *
 * La date et le statut de source appartiennent au volet, pas à la réponse :
 * les deux volets de la veille sont des instantanés distincts, rafraîchis par
 * le même travail planifié mais capables de réussir ou d'échouer séparément.
 * Un `refreshedAt` unique à la racine ne pourrait donc décrire que l'un des
 * deux, et laisserait croire à l'autre une fraîcheur qu'il n'a pas.
 */
final readonly class WatchReleaseCyclesResource
{
    /**
     * @param list<WatchedProductResource> $products
     * @param string|null                  $refreshedAt  date du dernier rafraîchissement abouti, en UTC
     *                                                   (ISO 8601), ou null si aucun n'a encore eu lieu
     * @param string|null                  $sourceStatus valeur de SnapshotSourceStatus, ou null si jamais rafraîchi
     * @param string                       $freshness    valeur de SnapshotFreshness : fresh, stale ou never_refreshed
     */
    public function __construct(
        public array $products,
        public ?string $refreshedAt,
        public ?string $sourceStatus,
        public string $freshness,
    ) {
    }
}
