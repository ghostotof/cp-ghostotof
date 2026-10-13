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
     * et remonte telle quelle. Levée après une écriture (un `flush()` qui
     * avait quelque chose à écrire, issue #549), elle clôt aussi l'unité de
     * travail de l'appelant, comme un COMMIT refusé ci-dessous ; un refus levé
     * avant toute écriture la laisse ouverte — d'où les refus d'un
     * `Administrator` placés avant son `save()`.
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
     * avant de rien écrire, en 500 nommé. Une seule exception, bornée (issue
     * #567) : sous une transaction de ce même adaptateur — un
     * {@see self::withLocks()}, ou un `withLock()` —, un `withLock()` d'un
     * périmètre **que cette transaction tient déjà** exécute `$operation` dans
     * cette transaction-là — ni nouvelle transaction, ni nouveau verrou, ni
     * relecture : rien n'a pu y être validé entre-temps. Un périmètre non
     * tenu reste refusé.
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

    /**
     * Exécute `$operation` dans **une** transaction, tous les périmètres de
     * `$scopes` verrouillés pour toute sa durée (issue #567) : ce qu'il faut à
     * une opération qui enchaîne des écritures de plusieurs périmètres, ou de
     * plusieurs `Administrator`s d'un même périmètre — un `app:*:seed`, dont
     * la purge et les recréations valident ou s'annulent ensemble. Les
     * verrous sont pris dans un ordre fixe, quel que soit celui de la liste,
     * pour que deux appels qui demandent les mêmes périmètres ne
     * s'interbloquent pas — un par instruction, donc l'attente est bornée par
     * périmètre, pas en tout : N périmètres, jusqu'à N fois `lock_timeout`.
     *
     * Sous cette transaction, un `withLock()` de ce même adaptateur sur l'un
     * de ces périmètres y participe (voir `withLock()`) : les `Administrator`s
     * s'appellent tels quels. Tout autre appel imbriqué — un périmètre non
     * tenu, un `withLocks()` — est refusé avant de rien écrire, comme dans
     * toute transaction déjà ouverte (issue #526) : un seed qui verrouille X
     * pendant que son `Administrator` écrit Y est une erreur de périmètre,
     * et elle doit se voir.
     *
     * Même contrat que `withLock()` pour le reste : relecture des entrées de
     * chaque périmètre chargées avant les verrous (issue #402), unité de
     * travail close sur un COMMIT refusé (#547) ou sur une exception levée
     * après une écriture (#549), refus différé nommé au COMMIT (#390).
     *
     * @template T
     *
     * @param non-empty-list<class-string> $scopes    les entités qui portent les positions
     *                                                des périmètres tenus ; une liste vide
     *                                                est refusée
     * @param Closure(): T                 $operation
     *
     * @return T
     */
    public function withLocks(array $scopes, Closure $operation): mixed;
}
