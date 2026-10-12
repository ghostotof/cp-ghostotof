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
 * Ce qui ne le prend pas : la création CLI (`app:user:create`), qui reçoit son
 * identifiant au lieu de le chercher, et ne sert qu'à l'amorçage. Une création
 * CLI validée pendant une invitation qui dérive le même identifiant fait
 * encore échouer celle-ci sur la contrainte, nommée alors pour ce qu'elle est
 * ({@see \App\Security\User\Domain\Exception\UsernameAlreadyUsedException}),
 * jamais pour une adresse prise. Limite acceptée.
 */
interface UsernameAllocationLockInterface
{
    /**
     * Exécute `$operation` dans une transaction, verrou pris pour toute sa
     * durée : ce qu'elle lit après l'acquisition tient compte de toute
     * invitation validée avant, et ce qu'elle écrit n'est visible des autres
     * qu'à la libération. Une exception de `$operation` annule la transaction
     * et remonte telle quelle.
     *
     * @template T
     *
     * @param Closure(): T $operation
     *
     * @return T
     */
    public function whileAllocating(Closure $operation): mixed;
}
