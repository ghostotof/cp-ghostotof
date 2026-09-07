<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Application;

/**
 * Interroge les sources externes et fige le résultat dans le snapshot courant.
 *
 * C'est le seul endroit de l'application qui provoque un appel sortant vers un
 * tiers. Il est déclenché par une commande planifiée, jamais par une requête de
 * visiteur (décision D5) : la page lit du local, et rien de ce qui se passe
 * chez le fournisseur ne peut ni la ralentir ni la casser.
 */
interface WatchRefresherInterface
{
    /**
     * @param \DateTimeImmutable $now    date de référence, passée explicitement pour
     *                                   que les échéances soient évaluables en test
     * @param bool               $dryRun calcule tout sans rien écrire
     */
    public function refresh(\DateTimeImmutable $now, bool $dryRun = false): WatchRefreshReport;
}
