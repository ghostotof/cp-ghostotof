<?php

declare(strict_types=1);

namespace App\Portfolio\Shared\Application;

use Closure;

/**
 * Sérialise les écritures qui **calculent** une position (issue #389).
 *
 * Placer une entrée (`ContentPlacement::atEndOf()`, `inGroup()`, `detach()`)
 * ou renuméroter un périmètre (`OrderAssigner::assign()`) est un
 * lire-calculer-écrire : sans verrou, une écriture concurrente du même
 * périmètre glisse entre la lecture et l'écriture, et l'une défait l'autre —
 * une version créée pendant un réordonnancement hérite de la position que son
 * groupe quitte, deux créations simultanées prennent la même fin de périmètre.
 *
 * Verrou de **périmètre**, pas de lignes : un `SELECT … FOR UPDATE` ne voit
 * pas les lignes insérées par la transaction qu'il attendait (lecture
 * fantôme), et ne verrouille rien sur une table vide.
 *
 * Chaque `Administrator` enveloppe dans `withLock()` le corps entier de ses
 * `create()`, `update()` et `reorder()` : toute lecture servant à placer
 * doit avoir lieu **après** l'acquisition, et l'écriture avant sa libération.
 */
interface OrderScopeLockInterface
{
    /**
     * Exécute `$operation` dans une transaction, périmètre `$scope` verrouillé
     * pour toute sa durée. Une écriture concurrente du même périmètre attend
     * la fin de la transaction, puis lit ce qu'elle a écrit.
     *
     * Une exception de `$operation` annule la transaction, relâche le verrou
     * et remonte telle quelle.
     *
     * @template T
     *
     * @param class-string $scope     l'entité qui porte les positions : ses lignes forment le
     *                                 périmètre. Une classe et non un nom de table, que cette
     *                                 couche n'a pas à connaître
     * @param Closure(): T $operation
     *
     * @return T
     */
    public function withLock(string $scope, Closure $operation): mixed;
}
