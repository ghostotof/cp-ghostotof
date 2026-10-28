<?php

declare(strict_types=1);

namespace App\Security\User\Application;

use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\CpgUserNotFoundException;
use Closure;

/**
 * Sérialise les écritures qui touchent à l'invitation d'un compte (issues
 * #435, #458) : l'émission d'un jeton par le handler d'invitation et
 * l'activation par le lien. Sans ce verrou commun, l'une pouvait passer entre
 * deux écritures de l'autre — deux jetons valables, ou un compte activé qui
 * en gardait un.
 *
 * L'opération s'exécute dans une transaction, compte verrouillé et relu ; elle
 * reçoit ce compte relu, à lire plutôt que tout état antérieur au verrou. Le
 * compte passé doit être une entité gérée (refusé sinon, avant de rien
 * verrouiller, issue #560), sans changement en attente : la
 * relecture écrase sans prévenir toute modification faite en mémoire. Ordre imposé à toute
 * opération : le compte d'abord, ses jetons ensuite — l'ordre inverse
 * provoquerait un interblocage.
 *
 * **Aucune relance ici** : un interblocage (`40P01`) annule la transaction et
 * remonte à l'appelant. Ceux dont l'opération se rejoue sans effet de bord
 * (changement de mot de passe, activation par lien) la demandent à
 * {@see DeadlockRetryInterface}, qui relit ce qu'ils tiennent ; les autres
 * (le handler d'invitation envoie un lien) n'y sont pas soumis.
 */
interface AccountLockInterface
{
    /**
     * Un COMMIT que la base refuse (issue #547, comme #502 pour le verrou
     * d'ordre) annule ce que l'opération a écrit, et **clôt l'unité de travail
     * de l'appelant** : une nouvelle lecture relit la base (une instance
     * déjà tenue garde les valeurs refusées), une écriture suivante par
     * l'ORM échoue — une instruction DQL `UPDATE`/`DELETE` ou DBAL brute
     * passe encore. Le refus est terminal pour l'unité de travail qui l'a
     * reçu. Sur ces tables sans contrainte différée, seule une session perdue
     * à ce moment-là peut le provoquer.
     *
     * Une exception de l'opération levée après une écriture (un `flush()` qui
     * avait quelque chose à écrire, issue #549) la clôt de même : une erreur
     * de la base sur le `DELETE` DQL qui suit les `save()` de l'activation ou
     * du changement de mot de passe, une session perdue. Levée avant toute
     * écriture (compte disparu, déjà activé), elle la laisse ouverte, et
     * l'appelant qui l'écarte continue.
     *
     * **Jamais dans une transaction déjà ouverte** (issue #532), ni sous un
     * autre verrou du compte ou de l'application : le verrou ouvre et valide
     * la sienne, et ce que fait l'appelant à son retour (le handler envoie le
     * lien d'invitation) suppose ce COMMIT. Imbriqué, il ne serait qu'un point
     * de sauvegarde : la ligne resterait verrouillée jusqu'au COMMIT de
     * l'appelant, et le lien partirait avant que son jeton soit validé. Un
     * appel imbriqué est refusé avant de verrouiller quoi que ce soit, sous une
     * exception nommée : un 500 sous FPM, des réessais Messenger puis
     * `failed` dans le worker.
     *
     * @template T
     *
     * @param Closure(CpgUser): T $operation reçoit le compte, relu sous le verrou
     *
     * @return T
     *
     * @throws CpgUserNotFoundException si le compte n'existe plus — l'instance passée est alors détachée,
     *                                  et la sauver de nouveau est refusé (issues #548, #560)
     */
    public function whileLocked(CpgUser $user, Closure $operation): mixed;
}
