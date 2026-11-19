<?php

declare(strict_types=1);

namespace App\Shared\Application;

use Closure;
use Throwable;

/**
 * Relance **une fois** une opération transactionnelle que la base a annulée pour
 * interblocage (`40P01`), la première fois (S3 de la seconde relecture de #650,
 * CR4).
 *
 * Le cas qui l'a fait naître : la révocation de tout le compte (changement de
 * mot de passe, rétrogradation) croise le premier échange du code d'un nouvel
 * accord MCP, et PostgreSQL annule l'une des deux (F1, épinglé par
 * `AccountWideRevocationDeadlockTest`). Annulée sans relance, la révocation
 * rendait un 500 à l'administrateur qui remédiait à un mot de passe compromis :
 * mot de passe inchangé, connexions vivantes, la remédiation échouait en silence.
 *
 * **Une seule source, et opt-in** : la relance n'est pas dans les verrous de
 * compte, dont chaque appelant a sa propre idempotence (le handler d'invitation
 * envoie un lien, la purge compte ce qu'elle supprime) ; chaque service qui sait
 * son opération rejouable la demande, et rejoue **toute** sa transaction
 * verrouillée — jamais un morceau. Son journal de sécurité s'écrit après le
 * retour, donc après le COMMIT du seul essai qui aboutit : une fois.
 *
 * **L'essai annulé laisse un EntityManager inutilisable** : la transaction
 * abandonnée a déjà écrit au point de sauvegarde, le squelette des verrous le
 * ferme alors (issue #549) et les entités chargées sont périmées. L'implémentation
 * le remet à neuf avant le second essai ; l'essai, lui, **relit** ce qu'il tient
 * d'avant (`$isRetry`) — un compte, un jeton — au lieu de le réutiliser.
 *
 * **Le service n'est pas propre aux comptes** : `McpClientAdministrator::remove()`
 * s'en sert aussi (#652). Contraintes de l'appelant, que rien ne garde dans le
 * code : (1) **aucune entité gérée n'est réutilisée après l'appel**, l'EntityManager
 * étant remis à neuf à la relance, toute instance chargée avant est détachée
 * de fait ; (2) **jamais `retryOnce()` dans une transaction englobante** : la
 * relance rejoue la transaction de l'essai, qu'une transaction extérieure
 * laisserait ouverte et annulée (`40P01` l'abandonne tout entière).
 */
interface DeadlockRetryInterface
{
    /**
     * @template T
     *
     * @param Closure(bool): T $attempt reçoit `false` au premier essai, `true` au second ; au second, relit toute entité tenue d'avant
     *
     * @return T le résultat du seul essai qui a abouti
     *
     * @throws Throwable l'exception du second essai, ou toute autre exception du premier : seul un interblocage se relance
     */
    public function retryOnce(Closure $attempt): mixed;
}
