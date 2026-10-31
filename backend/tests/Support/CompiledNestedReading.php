<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Ce que la garde des métadonnées a lu des paramètres et des liens (`uriVariables`) d'une ressource ou d'une
 * opération, en un seul parcours : les constats et le nombre de liens compilés vus (#748, R131).
 *
 * Un objet plutôt que deux parcours de `uriVariables` (l'un pour les constats, l'autre pour le décompte, chacun
 * avec son propre `instanceof Link`) : ce que l'on compte et ce que l'on juge ne peuvent pas diverger.
 */
final readonly class CompiledNestedReading
{
    /**
     * @param list<string> $found les constats, « <contexte>, paramètre|lien <nom> : security = <expr> »
     * @param int          $links le nombre de liens compilés parcourus (garde-fou : `src/` en a)
     */
    public function __construct(
        public array $found,
        public int $links,
    ) {
    }
}
