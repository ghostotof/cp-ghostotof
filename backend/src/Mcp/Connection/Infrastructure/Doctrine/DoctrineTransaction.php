<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\Doctrine;

use App\Mcp\Connection\Application\TransactionInterface;
use Closure;
use Doctrine\ORM\EntityManagerInterface;

/**
 * La transaction de l'application (#647) sur l'EntityManager :
 * `wrapInTransaction()` ouvre, vide l'unité de travail (`flush`) et valide au
 * retour de l'opération, annule si elle lève.
 *
 * Le prix connu de `wrapInTransaction()` : un échec **ferme** l'EntityManager
 * (voir {@see \App\Shared\Infrastructure\Lock\PostgresLockedTransaction}, qui
 * l'évite pour cette raison). Ici l'échec est soit un défaut du serveur (une
 * écriture refusée, un code que league n'a pu persister) qui finit la requête
 * en 500, soit, au point de jeton, un refus OAuth ordinaire (`invalid_grant`,
 * levé par league avant ou après une écriture) que le contrôleur rend tel
 * quel : dans les deux cas rien ne poursuit sur ce gestionnaire dans la
 * requête — le contrôleur ne fait plus que mettre en forme —, et une requête
 * suivante en reçoit un neuf (`kernel.reset`). Un appelant qui voudrait
 * continuer après un refus devra prendre l'autre squelette.
 */
final readonly class DoctrineTransaction implements TransactionInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function run(Closure $operation): mixed
    {
        // wrapInTransaction() passe l'EntityManager à l'opération, qui
        // l'ignore : elle n'en dépend pas.
        return $this->entityManager->wrapInTransaction($operation);
    }
}
