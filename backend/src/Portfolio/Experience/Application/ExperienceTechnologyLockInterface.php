<?php

declare(strict_types=1);

namespace App\Portfolio\Experience\Application;

use App\Portfolio\Experience\Domain\Entity\ExperienceTechnology;
use App\Portfolio\Experience\Domain\Exception\ExperienceTechnologyNotFoundException;
use Closure;
use Symfony\Component\Uid\Uuid;

/**
 * Sérialise les écritures du backoffice sur une même technologie, et les fait
 * décider sur ce que la base porte (issue #545).
 *
 * Le provider d'un `PUT` ou d'un `DELETE` charge la technologie avant le
 * processor, et `find()` la rend ensuite depuis la carte d'identité. Sans ce
 * verrou, une suppression validée entre ce chargement et le `flush()` laissait
 * l'`UPDATE` ou le `DELETE` ne toucher aucune ligne, sans rien lever (Doctrine
 * ne compte les lignes touchées que d'une entité versionnée) : le `PUT`
 * répondait 200 sur une technologie disparue, le perdant de deux `DELETE` 204.
 *
 * Un verrou de **ligne**, et non le verrou de périmètre des contenus ordonnés
 * ({@see \App\Portfolio\Shared\Application\OrderScopeLockInterface}) : une
 * technologie ne porte pas de position, rien n'est placé, et seule la ligne
 * écrite doit être tenue. La création n'est pas concernée : sa course est
 * gardée par l'index unique du nom.
 */
interface ExperienceTechnologyLockInterface
{
    /**
     * Exécute `$operation` dans une transaction, ligne de la technologie
     * verrouillée et relue : une écriture ou une suppression concurrente de la
     * même technologie attend la fin de la transaction. L'opération reçoit
     * l'instance relue, à modifier dans `$operation`, jamais avant : une
     * modification non écrite faite avant le verrou est écrasée.
     *
     * Une exception de `$operation` annule la transaction, relâche la ligne et
     * remonte telle quelle.
     *
     * Un COMMIT que la base refuse (issue #547, comme #502 pour le verrou
     * d'ordre) annule ce que l'opération a écrit, et **clôt l'unité de travail
     * de l'appelant** : une nouvelle lecture relit la base (une instance
     * déjà tenue garde les valeurs refusées), une écriture suivante par
     * l'ORM échoue — une instruction DQL `UPDATE`/`DELETE` ou DBAL brute
     * passe encore. Le refus est terminal pour l'unité de travail qui l'a
     * reçu. Sur cette table sans contrainte différée, seule une session perdue
     * à ce moment-là peut le provoquer.
     *
     * **Jamais dans une transaction déjà ouverte** : le verrou ouvre et valide
     * la sienne ; imbriqué, son COMMIT ne serait qu'un point de sauvegarde, et
     * la ligne resterait tenue jusqu'au COMMIT de l'appelant. Un appel imbriqué
     * est refusé avant de verrouiller quoi que ce soit, en 500 nommé.
     *
     * @template T
     *
     * @param Closure(ExperienceTechnology): T $operation reçoit la technologie, relue sous le verrou
     *
     * @return T
     *
     * @throws ExperienceTechnologyNotFoundException si la technologie n'existe pas, ou plus
     */
    public function whileLocked(Uuid $id, Closure $operation): mixed;
}
