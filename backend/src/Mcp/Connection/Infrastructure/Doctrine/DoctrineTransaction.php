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
 * l'évite pour cette raison). Ici
 * l'échec est un défaut du serveur (une écriture refusée, un code que league
 * n'a pu persister) qui finit la requête en 500 : rien ne poursuit sur ce
 * gestionnaire, et une requête suivante en reçoit un neuf.
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
