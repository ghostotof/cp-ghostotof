<?php

declare(strict_types=1);

namespace App\Portfolio\Shared\Application;

use Closure;

/**
 * Sérialise les écritures d'un périmètre d'ordre : celles qui **calculent**
 * une position (issue #389), et celles qui retirent ou modifient une entrée
 * sans la placer (issue #499).
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
 * Chaque `Administrator` de contenu ordonné enveloppe dans `withLock()` le
 * corps entier de ses `create()`, `update()`, `delete()` et `reorder()` :
 * toute lecture servant à placer doit avoir lieu **après** l'acquisition, et
 * l'écriture avant sa libération. Une suppression sous verrou ne peut pas
 * valider entre la relecture d'une autre opération et son écriture (#499) :
 * sans quoi l'`UPDATE` d'un `PUT` ne touchait aucune ligne, et le `PUT`
 * répondait 200 sur une entrée disparue.
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
     * Un COMMIT que la base refuse (issues #502, #547) annule ce que
     * l'opération a écrit, et **clôt l'unité de travail de l'appelant** : une
     * nouvelle lecture relit la base (une instance déjà tenue garde les
     * valeurs refusées), une écriture suivante par l'ORM échoue — une
     * instruction DQL `UPDATE`/`DELETE` ou DBAL brute passe encore. Le refus
     * est terminal pour l'unité de travail qui l'a reçu.
     *
     * **Jamais dans une transaction déjà ouverte** (issue #526), ni sous un
     * autre `withLock()` : le verrou ouvre et valide la sienne, seul moyen de
     * nommer ce que la base refuse au COMMIT. Un appel imbriqué est refusé
     * avant de rien écrire, en 500 nommé.
     *
     * Une fois le verrou pris, les entrées de `$scope` que l'appelant a déjà
     * chargées sont relues (issue #402) : `$operation` décide sur ce que la
     * base porte, pas sur ce qui a été lu avant le verrou. Une modification
     * non écrite de l'une d'elles est donc écrasée, et une entrée supprimée
     * entre-temps devient introuvable — à modifier dans `$operation`, jamais
     * avant.
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
