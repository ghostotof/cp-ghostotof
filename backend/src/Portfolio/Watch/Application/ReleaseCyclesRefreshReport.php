<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Application;

/**
 * Compte-rendu du volet « cycles de vie » d'un rafraîchissement.
 *
 * Les deux listes sont distinctes à dessein : un slug inconnu est une erreur de
 * contenu qu'on répare au backoffice, une source en échec est une panne qu'on
 * subit. Les additionner dans un unique compteur d'erreurs ferait perdre la
 * seule information utile pour décider quoi faire.
 */
final readonly class ReleaseCyclesRefreshReport
{
    /**
     * @param int          $refreshedCount nombre d'entrées écrites dans le snapshot
     * @param list<string> $unknownSlugs   produits absents du catalogue de la source
     * @param list<string> $failedSlugs    produits dont la source n'a pas répondu
     * @param bool         $persisted      false en simulation, ou quand il n'y avait rien à écrire
     */
    public function __construct(
        public int $refreshedCount,
        public array $unknownSlugs,
        public array $failedSlugs,
        public bool $persisted,
    ) {
    }
}
