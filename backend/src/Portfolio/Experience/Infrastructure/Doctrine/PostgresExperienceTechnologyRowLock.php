<?php

declare(strict_types=1);

namespace App\Portfolio\Experience\Infrastructure\Doctrine;

use App\Portfolio\Experience\Application\ExperienceTechnologyLockInterface;
use App\Portfolio\Experience\Domain\Entity\ExperienceTechnology;
use App\Portfolio\Experience\Domain\Exception\ExperienceTechnologyNotFoundException;
use App\Shared\Infrastructure\Doctrine\VanishedEntities;
use App\Shared\Infrastructure\Lock\PostgresLockedTransaction;
use Closure;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Le verrou de ligne d'une technologie (`SELECT … FOR UPDATE`), dans une
 * transaction de la connexion de l'ORM (issue #545). Même rôle que le verrou
 * du compte, {@see \App\Security\User\Infrastructure\Doctrine\PostgresAccountRowLock}.
 *
 * - Le squelette des verrous PostgreSQL, {@see PostgresLockedTransaction}
 *   (issue #512) : une transaction à la main — un refus levé par l'opération
 *   (le 409 d'un nom déjà pris) est une issue ordinaire, qui ne doit pas
 *   fermer l'EntityManager —, l'attente bornée si la session ne la borne pas,
 *   son `55P03` renommé en {@see ExperienceTechnologyLockTimeoutException}, et
 *   le refus de s'imbriquer, ici {@see ExperienceTechnologyLockInsideTransactionException}.
 * - **Une seule requête** verrouille, dit si la ligne existe et relit :
 *   `SELECT t … FOR UPDATE` en DQL, `HINT_REFRESH` posé, qui rafraîchit sur
 *   place l'instance déjà gérée (celle du provider) et rend `null` si la ligne
 *   a disparu. Ni `refresh()` ni `find($id, LockMode::PESSIMISTIC_WRITE)` ne
 *   le disent : sur une instance déjà gérée, le second fait un `refresh()`
 *   avec `FOR UPDATE`, qui n'hydrate rien quand la ligne a disparu et rend
 *   l'instance périmée.
 * - Ligne absente : l'instance périmée que l'EntityManager gère encore est
 *   détachée, pour qu'un appelant qui continue (une commande) ne la croie pas
 *   en base, puis la technologie est dite introuvable (404). Détachée par
 *   {@see VanishedEntities}, qui refuse qu'on la sauve de nouveau : Doctrine
 *   la tiendrait pour neuve et la réinsérerait (issue #548). Une instance
 *   planifiée pour insertion ou suppression n'est pas détachée : ce serait
 *   annuler en silence son écriture en attente (même filtre que la relecture
 *   du verrou d'ordre, #402).
 *
 * Un verrou de **ligne**, pas un advisory lock : PostgreSQL l'impose à tout
 * `UPDATE` et `DELETE` de la ligne, ceux d'une autre session SQL compris. Une
 * suppression ne peut donc valider ni entre la relecture et le `flush()`, ni
 * hors de l'application. Et il ne tient que la ligne écrite : deux
 * technologies différentes ne s'attendent jamais.
 *
 * **COMMIT refusé** : aucun `onCommitRefused` n'est passé, il n'y a rien à
 * nommer. `experience_technology` n'a aucune contrainte différée (l'unicité du
 * nom et le `CHECK` des années sont immédiates, et refusent au `flush()`, qui
 * ferme alors l'EntityManager de lui-même) : un COMMIT refusé ne peut venir
 * que d'une session perdue à ce moment-là. La `DriverException` remonte telle
 * quelle, un 500, et le squelette ferme l'EntityManager, dont l'UnitOfWork
 * tenait pour écrit ce que le refus venait d'annuler (issue #547, comme pour
 * les autres verrous du squelette). Une exception levée par l'opération après
 * une écriture le ferme de même (issue #549). Le 409 d'un nom déjà pris le
 * laisse ouvert quand il vient de la vérification, avant le `save()` ; venu de
 * l'index unique (la course de deux `PUT`), c'est le `flush()` raté qui l'a
 * déjà fermé.
 */
final readonly class PostgresExperienceTechnologyRowLock implements ExperienceTechnologyLockInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private VanishedEntities $vanishedEntities,
        private string $fallbackLockTimeout = PostgresLockedTransaction::FALLBACK_LOCK_TIMEOUT,
    ) {
    }

    public function whileLocked(Uuid $id, Closure $operation): mixed
    {
        return new PostgresLockedTransaction($this->entityManager, $this->logger, $this->fallbackLockTimeout)->run(
            // La lecture verrouillée est l'acquisition : c'est son attente que
            // le squelette borne et renomme.
            acquire: fn (): ExperienceTechnology => $this->lockAndReread($id),
            onTimeout: static fn (DriverException $timeout): ExperienceTechnologyLockTimeoutException => ExperienceTechnologyLockTimeoutException::forTechnology($id->toRfc4122(), $timeout),
            onNested: static fn (): ExperienceTechnologyLockInsideTransactionException => ExperienceTechnologyLockInsideTransactionException::forTechnology($id->toRfc4122()),
            operation: fn (): mixed => $operation($this->lockedInstance($id)),
        );
    }

    /**
     * L'instance que l'acquisition vient de verrouiller et relire : `find()`
     * la rend de la carte d'identité, sans requête. Introuvable ici serait une
     * ligne supprimée sous notre verrou, ce que PostgreSQL empêche ; le cas
     * reste traité plutôt que supposé.
     */
    private function lockedInstance(Uuid $id): ExperienceTechnology
    {
        return $this->entityManager->find(ExperienceTechnology::class, $id)
            ?? throw ExperienceTechnologyNotFoundException::forId($id);
    }

    /**
     * Verrouille la ligne et rend l'instance gérée, relue ; introuvable si la
     * ligne a disparu.
     */
    private function lockAndReread(Uuid $id): ExperienceTechnology
    {
        /** @var ExperienceTechnology|null $technology */
        $technology = $this->entityManager->createQueryBuilder()
            ->select('technology')
            ->from(ExperienceTechnology::class, 'technology')
            ->andWhere('technology.id = :id')
            ->setParameter('id', $id, UuidType::NAME)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        if (null === $technology) {
            $this->forgetStaleInstance($id);

            throw ExperienceTechnologyNotFoundException::forId($id);
        }

        return $technology;
    }

    /**
     * La ligne a disparu : détache l'instance que la carte d'identité tient
     * encore (sans requête), sauf si une écriture l'attend.
     */
    private function forgetStaleInstance(Uuid $id): void
    {
        $unitOfWork = $this->entityManager->getUnitOfWork();
        $stale = $unitOfWork->tryGetById(['id' => $id], ExperienceTechnology::class);

        if (false !== $stale && !$unitOfWork->isScheduledForInsert($stale) && !$unitOfWork->isScheduledForDelete($stale)) {
            // Détachée et retenue : re-sauvée, elle serait réinsérée (#548).
            $this->vanishedEntities->detachVanished($this->entityManager, $stale);
        }
    }
}
