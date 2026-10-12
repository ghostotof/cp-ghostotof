<?php

declare(strict_types=1);

namespace App\Portfolio\Experience\Infrastructure\Doctrine;

use App\Portfolio\Experience\Application\ExperienceTechnologyLockInterface;
use App\Portfolio\Experience\Domain\Entity\ExperienceTechnology;
use App\Portfolio\Experience\Domain\Exception\ExperienceTechnologyNotFoundException;
use App\Shared\Infrastructure\Lock\PostgresLockedTransaction;
use Closure;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Le verrou de ligne d'une technologie (`SELECT … FOR UPDATE`), dans une
 * transaction de la connexion de l'ORM (issue #545). Même forme que le verrou
 * du compte, {@see \App\Security\User\Infrastructure\Doctrine\PostgresAccountRowLock}.
 *
 * - Le squelette des verrous PostgreSQL, {@see PostgresLockedTransaction}
 *   (issue #512) : une transaction à la main — un refus levé par l'opération
 *   (le 409 d'un nom déjà pris) est une issue ordinaire, qui ne doit pas
 *   fermer l'EntityManager —, l'attente bornée si la session ne la borne pas,
 *   son `55P03` renommé en {@see ExperienceTechnologyLockTimeoutException}, et
 *   le refus de s'imbriquer, ici {@see ExperienceTechnologyLockInsideTransactionException}.
 * - Une lecture qui dit si la ligne existe. Ni `refresh()` ni
 *   `find($id, LockMode::PESSIMISTIC_WRITE)` ne le disent : sur une instance
 *   déjà gérée (celle du provider), le second fait un `refresh()` avec
 *   `FOR UPDATE`, qui n'hydrate rien quand la ligne a disparu et rend
 *   l'instance périmée. Ligne absente : l'instance périmée est détachée, puis
 *   la technologie est dite introuvable (404).
 * - Ligne tenue : l'instance gérée est relue (`refresh()`), une requête sur le
 *   chemin HTTP, où le provider l'a déjà chargée. L'opération décide sur ce
 *   que la base porte.
 *
 * Un verrou de **ligne**, pas un advisory lock : PostgreSQL l'impose à tout
 * `UPDATE` et `DELETE` de la ligne, ceux d'une autre session SQL compris. Une
 * suppression ne peut donc valider ni entre la relecture et le `flush()`, ni
 * hors de l'application. Et il ne tient que la ligne écrite : deux
 * technologies différentes ne s'attendent jamais.
 *
 * **COMMIT refusé** : aucun `onCommitRefused` n'est passé. `experience_technology`
 * n'a aucune contrainte différée (l'unicité du nom et le `CHECK` des années
 * sont immédiates, et refusent au `flush()`, qui ferme alors l'EntityManager
 * de lui-même) : un COMMIT refusé ne peut venir que d'une session perdue à ce
 * moment-là. La `DriverException` remonte telle quelle, un 500, et
 * l'EntityManager reste ouvert, comme sous les deux verrous du compte (sa
 * fermeture sur ce cas relève de #547).
 */
final readonly class PostgresExperienceTechnologyRowLock implements ExperienceTechnologyLockInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private string $fallbackLockTimeout = PostgresLockedTransaction::FALLBACK_LOCK_TIMEOUT,
    ) {
    }

    public function whileLocked(Uuid $id, Closure $operation): mixed
    {
        $connection = $this->entityManager->getConnection();
        $table = $this->entityManager->getClassMetadata(ExperienceTechnology::class)->getTableName();

        return new PostgresLockedTransaction($connection, $this->logger, $this->fallbackLockTimeout)->run(
            acquire: function () use ($connection, $table, $id): void {
                $locked = $connection->fetchOne(
                    \sprintf('SELECT 1 FROM %s WHERE id = :id FOR UPDATE', $table),
                    ['id' => $id],
                    ['id' => UuidType::NAME],
                );
                if (false === $locked) {
                    $this->forgetStaleInstance($id);

                    throw ExperienceTechnologyNotFoundException::forId($id);
                }
            },
            onTimeout: static fn (DriverException $timeout): ExperienceTechnologyLockTimeoutException => ExperienceTechnologyLockTimeoutException::forTechnology($id->toRfc4122(), $timeout),
            onNested: static fn (): ExperienceTechnologyLockInsideTransactionException => ExperienceTechnologyLockInsideTransactionException::forTechnology($id->toRfc4122()),
            operation: fn (): mixed => $operation($this->reread($id)),
        );
    }

    /**
     * La ligne est tenue : `find()` rend l'instance gérée (sans requête si le
     * provider l'a chargée) ou la charge, et `refresh()` la relit. Introuvable
     * ici serait une ligne supprimée sous notre verrou, ce que PostgreSQL
     * empêche ; le cas reste traité plutôt que supposé.
     */
    private function reread(Uuid $id): ExperienceTechnology
    {
        $technology = $this->entityManager->find(ExperienceTechnology::class, $id);
        if (null === $technology) {
            throw ExperienceTechnologyNotFoundException::forId($id);
        }

        $this->entityManager->refresh($technology);

        return $technology;
    }

    /**
     * La ligne a disparu : l'instance que l'EntityManager gère encore (celle
     * du provider) est détachée, pour qu'un appelant qui continue (une
     * commande) ne la croie pas en base. `find()` ne fait aucune requête sur
     * une instance gérée, et ne trouve rien sinon.
     */
    private function forgetStaleInstance(Uuid $id): void
    {
        $stale = $this->entityManager->find(ExperienceTechnology::class, $id);
        if (null !== $stale) {
            $this->entityManager->detach($stale);
        }
    }
}
