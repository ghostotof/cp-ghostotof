<?php

declare(strict_types=1);

namespace App\Security\User\Application;

use Closure;

/**
 * Sérialise les invitations, du choix de l'identifiant à son enregistrement
 * (issue #364).
 *
 * `UsernameGenerator` choisit un identifiant libre en le cherchant en base :
 * un lire-calculer-écrire. Sans verrou, deux invitations simultanées de même
 * partie locale (`jean@a.example`, `jean@b.example`) voyaient toutes deux
 * `jean` libre, et la seconde échouait sur la contrainte unique. Sous le
 * verrou, la seconde attend que la première ait validé, puis voit `jean` pris
 * et prend `jean2` — le résultat de deux invitations successives. La même
 * attente règle la course de deux invitations de la même adresse : la seconde
 * trouve le compte de la première et répond « adresse déjà utilisée ».
 *
 * Verrou de **périmètre**, pas de ligne : la ligne en conflit n'existe pas
 * encore quand la seconde invitation lit, et un `SELECT … FOR UPDATE` ne
 * verrouille rien qu'il ne trouve.
 *
 * Le prennent tous les cas d'usage qui créent un compte : l'invitation
 * (`CpgUserInviter`) et la création CLI (`CpgUserRegistrar`), qui reçoit son
 * identifiant mais doit aussi le voir pris par une invitation, et le faire
 * voir. Une écriture qui ne le prend pas (saisie à la main en base) n'est
 * arrêtée que par la contrainte unique, que
 * {@see \App\Security\User\Domain\Repository\CpgUserRepositoryInterface::add()}
 * nomme pour ce qu'elle est.
 *
 * Une seule clé pour tous : une invitation lente (une écriture qui attend
 * elle-même un verrou) fait attendre toutes les autres, jusqu'au délai de
 * l'adaptateur, au-delà duquel celui-ci lève une exception nommée (500). Au
 * volume des invitations, le plafond est accepté.
 */
interface UsernameAllocationLockInterface
{
    /**
     * Exécute `$operation` dans une transaction, verrou pris pour toute sa
     * durée : ce qu'elle lit après l'acquisition tient compte de toute
     * invitation validée avant, et ce qu'elle écrit n'est visible des autres
     * qu'à la libération. Une exception de `$operation` annule la transaction
     * et remonte telle quelle. Levée après une écriture (un `flush()` qui
     * avait quelque chose à écrire, issue #549), elle clôt aussi l'unité de
     * travail de l'appelant, comme un COMMIT refusé ci-dessous ; levée avant
     * (adresse ou identifiant déjà pris), elle la laisse ouverte.
     *
     * Un COMMIT que la base refuse (issue #547, comme #502 pour le verrou
     * d'ordre) annule ce que l'opération a écrit, et **clôt l'unité de travail
     * de l'appelant** : une nouvelle lecture relit la base (une instance
     * déjà tenue garde les valeurs refusées), une écriture suivante par
     * l'ORM échoue — une instruction DQL `UPDATE`/`DELETE` ou DBAL brute
     * passe encore. Le refus est terminal pour l'unité de travail qui l'a
     * reçu. Sur ces tables sans contrainte différée, seule une session perdue
     * à ce moment-là peut le provoquer.
     *
     * **Jamais dans une transaction déjà ouverte** (issue #532), ni sous un
     * autre verrou de l'application : le retour de cette méthode *est* le
     * COMMIT, et l'appelant s'y fie (`CpgUserInviter::invite()` ne publie
     * l'invitation qu'après). Imbriqué, ce ne serait qu'un point de
     * sauvegarde : le worker chercherait un compte encore invisible et
     * abandonnerait le message sans réessai, le verrou tenant toutes les
     * invitations jusqu'au COMMIT de l'appelant. Un appel imbriqué est refusé
     * avant de prendre le verrou, sous une exception nommée (un 500 depuis
     * l'API, une erreur de la commande en CLI).
     *
     * @template T
     *
     * @param Closure(): T $operation
     *
     * @return T
     */
    public function whileAllocating(Closure $operation): mixed;
}
