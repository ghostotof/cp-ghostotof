<?php

declare(strict_types=1);

namespace App\Security\User\Application;

use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\ActingAccountNoLongerSuperAdminException;
use App\Security\User\Domain\Exception\CpgUserNotFoundException;
use Closure;

/**
 * Sérialise l'administration des comptes par les super-administrateurs
 * (issues #551, #552) : la suppression d'un compte et le changement de son
 * rôle ROLE_SUPER. La garde « jamais le dernier ROLE_SUPER » comptait puis
 * agissait, sans verrou : deux super-administrateurs qui se supprimaient ou se
 * rétrogradaient l'un l'autre passaient chacun la garde, et il n'en restait
 * aucun. Un verrou global, et non la seule ligne du compte administré, parce
 * que les deux requêtes portent sur deux comptes différents.
 *
 * L'opération s'exécute dans une transaction, sous un verrou **global** de
 * l'administration, la ligne du compte administré verrouillée et relue, et
 * **l'acteur relu** : le pare-feu l'a autorisé au début de la requête, mais un
 * autre super-administrateur a pu le rétrograder ou le supprimer depuis — ce
 * que le pare-feu refusera à sa requête suivante, le verrou le refuse à
 * celle-ci. L'opération reçoit le compte administré relu, à lire plutôt que
 * tout état antérieur au verrou ; la garde du dernier ROLE_SUPER s'y compte
 * **en base** (`CpgUserRepositoryInterface::countOtherSuperAdminsAbleToSignIn()`),
 * jamais sur la carte d'identité, où l'acteur et la cible sont ceux d'avant.
 * Le journal de sécurité s'écrit au retour, après le COMMIT : une action
 * journalisée est une action validée.
 *
 * Les deux comptes passés doivent être des entités gérées, sans changement
 * en attente : la relecture écrase sans prévenir toute modification faite en
 * mémoire. Un compte administré disparu est détaché et dit introuvable (404,
 * le perdant de deux suppressions, ou l'administration d'un compte que la
 * purge vient d'emporter) ; un acteur disparu ou rétrogradé est un refus (403).
 * Ni l'un ni l'autre n'écrit ni ne journalise rien.
 *
 * **Jamais dans une transaction déjà ouverte** (issue #532), ni sous un autre
 * verrou : refusé avant de rien prendre, sous une exception nommée (un 500
 * `critical`). Pris **après** aucun autre verrou de compte : l'opération ne
 * prend que lui, et les détenteurs du verrou de ligne d'un compte
 * (`AccountLockInterface`) n'attendent jamais celui-ci — aucun cycle possible.
 */
interface AccountAdministrationLockInterface
{
    /**
     * Un COMMIT que la base refuse (issue #547), ou une exception de
     * l'opération levée après une écriture (issue #549), clôt l'unité de
     * travail de l'appelant, comme sous `AccountLockInterface::whileLocked()` ;
     * un refus levé avant toute écriture la laisse ouverte.
     *
     * @template T
     *
     * @param CpgUser             $target        le compte administré, relu et verrouillé sous le verrou
     * @param CpgUser             $actingUser    le super-administrateur qui agit, relu sous le verrou
     * @param Closure(CpgUser): T $operation     reçoit le compte administré, relu sous le verrou
     *
     * @return T
     *
     * @throws CpgUserNotFoundException                 si le compte administré n'existe plus
     * @throws ActingAccountNoLongerSuperAdminException si l'acteur n'existe plus ou n'est plus ROLE_SUPER
     */
    public function whileAdministering(CpgUser $target, CpgUser $actingUser, Closure $operation): mixed;
}
