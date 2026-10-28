<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\Doctrine;

use App\Mcp\Connection\Application\ReadSnapshotInterface;
use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

/**
 * L'instantané de lecture (#650, CR3 de la seconde relecture) par une
 * transaction `REPEATABLE READ READ ONLY` de PostgreSQL : l'instantané est pris
 * à la première requête de la fermeture, toutes les suivantes le partagent.
 *
 * **Lecture seule**, donc aucun verrou de ligne : un rafraîchissement ou la
 * purge n'attendent jamais une liste en cours de lecture, et la base refuse
 * toute écriture de la fermeture (`READ ONLY`) — le contrat « ne fait que
 * lire » est tenu par PostgreSQL, pas par l'appelant. **Aucun conflit de
 * sérialisation** (`40001`) non plus : en `REPEATABLE READ` il ne naît que d'une
 * mise à jour d'une ligne modifiée depuis l'instantané, et celui-ci n'en fait
 * aucune.
 *
 * `SET TRANSACTION` plutôt que `BEGIN ISOLATION LEVEL …` : DBAL 4 ouvre sa
 * transaction par `beginTransaction()` sans option, et le niveau se règle encore
 * avant la première requête. **Refus d'une transaction déjà ouverte**
 * ({@see ReadSnapshotInsideTransactionException}) : imbriqué, DBAL ouvrirait un
 * point de sauvegarde et `SET TRANSACTION` échouerait ou, pire, ne dirait rien
 * de l'instantané. Ni `wrapInTransaction()` : il vide l'unité de travail, et un
 * échec ferme l'EntityManager ({@see DoctrineTransaction}), deux effets que
 * ni une lecture ni sa liste n'ont à subir.
 */
final readonly class DoctrineReadSnapshot implements ReadSnapshotInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function read(Closure $reads): mixed
    {
        $connection = $this->entityManager->getConnection();
        if ($connection->isTransactionActive()) {
            throw ReadSnapshotInsideTransactionException::create();
        }

        $connection->beginTransaction();
        try {
            $connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $result = $reads();
        } catch (Throwable $failure) {
            // Annulée : rien n'a été écrit, l'exception d'origine remonte telle quelle.
            $connection->rollBack();

            throw $failure;
        }

        $connection->commit();

        return $result;
    }
}
