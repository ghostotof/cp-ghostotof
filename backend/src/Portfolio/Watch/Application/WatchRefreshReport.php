<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Application;

/**
 * Compte-rendu d'un rafraîchissement, destiné à l'appelant (commande, plus tard
 * supervision). Ce n'est pas un concept métier : il ne franchit pas la
 * frontière vers le domaine et n'est jamais persisté.
 *
 * Les deux listes sont distinctes à dessein : un slug inconnu est une erreur de
 * contenu qu'on répare au backoffice, une source en échec est une panne qu'on
 * subit. Les additionner dans un unique compteur d'erreurs ferait perdre la
 * seule information utile pour décider quoi faire.
 */
final readonly class WatchRefreshReport
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
