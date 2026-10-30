<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Un site d'autorisation que `AuthorizationSites` a recensé : sa ligne et sa nature (#748, St8-8).
 *
 * Un objet plutôt qu'un tuple `[int, nature]` lu par position : le test et ses fournisseurs de
 * cas nomment ce qu'ils lisent, et un tuple dont on inverserait les deux membres ne serait
 * refusé que par le typage de l'analyseur.
 */
final readonly class AuthorizationSite
{
    public function __construct(
        /** La ligne du jeton qui porte le site, à partir de 1. */
        public int $line,
        public AuthorizationSiteKind $kind,
    ) {
    }
}
