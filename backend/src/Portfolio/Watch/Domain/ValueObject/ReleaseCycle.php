<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Domain\ValueObject;

/**
 * Une ligne de version d'un produit (« PHP 8.5 »), avec ses échéances.
 *
 * Le vocabulaire du fournisseur s'arrête à la frontière de l'infrastructure :
 * endoflife.date parle d'`eoas`, le domaine parle de fin de support actif. Un
 * acronyme propre à un tiers qui se répandrait dans le domaine rendrait le jour
 * du changement de fournisseur beaucoup plus coûteux qu'il ne doit l'être.
 */
final readonly class ReleaseCycle
{
    public function __construct(
        /** Nom du cycle, ex. « 8.5 » — pas une version complète. */
        public string $name,
        /** Le cycle reçoit encore des correctifs, sécurité comprise. */
        public bool $isMaintained,
        /** Le support actif est terminé : correctifs de sécurité seulement. */
        public bool $isEndOfActiveSupport,
        public ?\DateTimeImmutable $endOfActiveSupportFrom,
        /** Plus aucun correctif, pas même de sécurité. */
        public bool $isEol,
        public ?\DateTimeImmutable $eolFrom,
        /** Dernier correctif publié sur ce cycle, ex. « 8.5.10 ». */
        public ?string $latestVersion,
    ) {
    }
}
