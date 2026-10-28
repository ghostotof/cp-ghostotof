<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use Closure;

/**
 * Plusieurs lectures qui voient **le même instant** de la base (#650, CR3 de la
 * seconde relecture), vues de l'application.
 *
 * « Mes connexions MCP » lit deux moitiés (les non révoquées, les terminées) en
 * deux requêtes. En lecture validée (le niveau par défaut de PostgreSQL), chaque
 * requête voit ce qui est validé *à son départ* : une connexion révoquée entre
 * les deux disparaît de la liste (vue active par la première, plus par la
 * seconde, ou l'inverse selon l'ordre) ou y figure deux fois. Dans un instantané
 * commun, la liste est celle d'un instant.
 *
 * Distincte de {@see TransactionInterface} : celle-ci écrit, vide l'unité de
 * travail et peut fermer l'EntityManager ; un instantané ne fait que lire, ne
 * verrouille rien (aucun rafraîchissement, aucune purge n'attend) et se valide à
 * vide. Une interface plutôt que Doctrine : la couche Application n'en dépend pas
 * (Infrastructure\Doctrine\DoctrineReadSnapshot).
 */
interface ReadSnapshotInterface
{
    /**
     * Exécute `$reads` dans un instantané : toutes les lectures de la fermeture
     * voient la base telle qu'elle était à la première. Ne fait qu'y lire.
     *
     * @template T
     *
     * @param Closure(): T $reads
     *
     * @return T
     */
    public function read(Closure $reads): mixed;
}
