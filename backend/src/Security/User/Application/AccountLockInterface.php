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
 * compte passé doit être une entité gérée, sans changement en attente : la
 * relecture écrase sans prévenir toute modification faite en mémoire. Ordre imposé à toute
 * opération : le compte d'abord, ses jetons ensuite — l'ordre inverse
 * provoquerait un interblocage.
 */
interface AccountLockInterface
{
    /**
     * Un COMMIT que la base refuse (issue #547, comme #502 pour le verrou
     * d'ordre) annule ce que l'opération a écrit, et **clôt l'unité de travail
     * de l'appelant** : une lecture suivante relit la base, une écriture
     * suivante échoue. Le refus est terminal pour l'unité de travail qui l'a
     * reçu. Sur ces tables sans contrainte différée, seule une session perdue
     * à ce moment-là peut le provoquer.
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
     * @throws CpgUserNotFoundException si le compte n'existe plus
     */
    public function whileLocked(CpgUser $user, Closure $operation): mixed;
}
