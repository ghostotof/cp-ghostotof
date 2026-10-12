<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\Doctrine;

use App\Security\User\Application\AccountAdministrationLockInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\ActingAccountNoLongerSuperAdminException;
use App\Security\User\Domain\Exception\CpgUserNotFoundException;
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
 * Le verrou d'administration des comptes (issues #551, #552) : un advisory
 * lock PostgreSQL **de transaction**, global, puis la ligne du compte
 * administré (`SELECT … FOR UPDATE`), puis l'acteur relu — sur la connexion de
 * l'ORM, dans une transaction du squelette {@see PostgresLockedTransaction}.
 *
 * - **Global, de transaction** (`pg_advisory_xact_lock`) : la garde « jamais
 *   le dernier ROLE_SUPER » compte tous les comptes, deux administrations de
 *   deux comptes différents doivent donc s'enchaîner, pas se croiser. Relâché
 *   par le COMMIT ou le ROLLBACK eux-mêmes, il ne peut ni précéder l'écriture
 *   visible, ni fuir. Forme à deux clés `int4` : même première clé que le
 *   verrou d'attribution des identifiants (« USER »), seconde clé distincte
 *   (« ADMN » contre « NAME ») ; le périmètre d'ordre a sa propre première
 *   clé. Les administrations sont rares, les sérialiser toutes ne coûte rien.
 * - **Puis l'acteur, relu sans verrouiller sa ligne** : une requête DQL avec
 *   `HINT_REFRESH`, sans `FOR UPDATE`. Cohérent malgré tout : toute
 *   rétrogradation ou suppression par l'application passe par ce même
 *   advisory lock, donc est validée avant cette lecture ou attend après
 *   elle ; seul un SQL manuel y échappe (limite acceptée, comme pour la
 *   garde elle-même). Disparu ou sans ROLE_SUPER : refusé (403), l'instance
 *   disparue détachée. Relu **avant** la cible (relecture de #551) : un
 *   acteur révoqué reçoit le même 403 que sa cible existe ou non — relu
 *   après, il aurait reçu un 404 sur un identifiant absent, un 403 sinon.
 * - **Puis la ligne du compte administré**, `FOR UPDATE` : une seule requête
 *   DQL verrouille, dit si la ligne existe et relit sur place l'instance que
 *   le provider a chargée (`HINT_REFRESH`), comme le verrou de ligne d'une
 *   technologie (#545) — `refresh()` sur une ligne supprimée ne lève rien.
 *   Ligne absente : l'instance périmée est détachée par {@see VanishedEntities}
 *   (re-sauvée, Doctrine la réinsérerait, issue #548), puis le compte est dit
 *   introuvable — le 404 du perdant de deux `DELETE`, ou d'un `PUT` des rôles
 *   sur un compte que la purge vient d'emporter (#552), sans ligne d'audit.
 *
 * **Ordre d'acquisition, sans interblocage** : l'advisory lock d'abord, la
 * ligne ensuite. Aucun autre chemin ne prend cet advisory lock, et les
 * détenteurs d'un verrou de ligne de compte — `PostgresAccountRowLock` pour
 * l'invitation, l'activation, la purge et le changement de mot de passe,
 * `advanceInvitationGeneration()` le temps d'un `UPDATE` — n'attendent jamais
 * l'advisory lock : un cycle demanderait qu'un détenteur de ligne attende
 * l'advisory, ce que personne ne fait. Deux administrations sont sérialisées
 * par l'advisory avant de toucher une ligne. La lecture de l'acteur, sans
 * verrou, n'attend rien. **Prix accepté** : une administration qui attend une
 * ligne tenue par le handler d'invitation, la purge ou un changement de mot
 * de passe (quelques millisecondes) tient l'advisory lock pendant ce temps,
 * et toute autre administration attend derrière elle ; les deux attentes sont
 * bornées par `lock_timeout`, et chacune a son nom.
 *
 * Le squelette — transaction à la main, attente bornée si la session ne la
 * borne pas, `55P03` renommé en {@see AccountAdministrationLockTimeoutException}
 * (l'advisory lock et la ligne, chacun sous sa fabrique : le journal dit
 * laquelle des deux attentes a dépassé), COMMIT refusé remonté sous l'erreur de la
 * base et l'EntityManager fermé (#547), fermé aussi sur une exception levée
 * après une écriture (#549), refus de s'imbriquer sous
 * {@see AccountAdministrationLockInsideTransactionException} (#532) — est celui
 * des verrous PostgreSQL de l'application (issue #512). Aucun
 * `onCommitRefused` : `cpg_user` n'a aucune contrainte différée, un COMMIT
 * refusé ne peut venir que d'une session perdue, il remonte tel quel.
 *
 * Suppose READ COMMITTED, le niveau par défaut que rien ne change ici : la
 * première lecture après le verrou voit ce que son détenteur précédent a
 * validé.
 */
final readonly class PostgresAccountAdministrationLock implements AccountAdministrationLockInterface
{
    /** Première clé : « USER » en ASCII, celle de PostgresUsernameAllocationLock. */
    public const int ADVISORY_NAMESPACE = PostgresUsernameAllocationLock::ADVISORY_NAMESPACE;

    /** Seconde clé : « ADMN » en ASCII, distincte de « NAME ». */
    public const int ADVISORY_KEY = 0x41444D4E;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private VanishedEntities $vanishedEntities,
        private string $fallbackLockTimeout = PostgresLockedTransaction::FALLBACK_LOCK_TIMEOUT,
    ) {
    }

    public function whileAdministering(CpgUser $target, CpgUser $actingUser, Closure $operation): mixed
    {
        $targetId = $target->getId();
        $actingUserId = $actingUser->getId();

        return new PostgresLockedTransaction($this->entityManager, $this->logger, $this->fallbackLockTimeout)->run(
            // L'acquisition entière — l'advisory lock, l'acteur, la ligne —
            // est ce que le squelette borne et renomme ; ses refus (403, 404)
            // précèdent toute écriture. L'attente de la ligne est nommée par
            // lockAndRereadTarget() elle-même ; celle que le squelette renomme
            // est donc celle de l'advisory lock (la lecture de l'acteur
            // n'attend aucun verrou).
            acquire: function () use ($targetId, $actingUserId): void {
                $this->entityManager->getConnection()->executeQuery(
                    'SELECT pg_advisory_xact_lock(?, ?)',
                    [self::ADVISORY_NAMESPACE, self::ADVISORY_KEY],
                );
                $this->rereadActingUser($actingUserId);
                $this->lockAndRereadTarget($targetId);
            },
            onTimeout: static fn (DriverException $timeout): AccountAdministrationLockTimeoutException => AccountAdministrationLockTimeoutException::advisoryLockHeld($targetId->toRfc4122(), $timeout),
            onNested: static fn (): AccountAdministrationLockInsideTransactionException => AccountAdministrationLockInsideTransactionException::forAccount($targetId->toRfc4122()),
            operation: fn (): mixed => $operation($this->lockedInstance($targetId)),
        );
    }

    /**
     * L'instance que l'acquisition vient de verrouiller et relire : `find()`
     * la rend de la carte d'identité, sans requête. Introuvable ici serait
     * une ligne supprimée sous notre verrou, ce que PostgreSQL empêche ; le
     * cas reste traité plutôt que supposé.
     */
    private function lockedInstance(Uuid $targetId): CpgUser
    {
        return $this->entityManager->find(CpgUser::class, $targetId)
            ?? throw CpgUserNotFoundException::forId($targetId);
    }

    /**
     * Verrouille la ligne du compte administré et la relit ; introuvable si
     * elle a disparu.
     */
    private function lockAndRereadTarget(Uuid $targetId): void
    {
        try {
            $target = $this->reread($targetId, forUpdate: true);
        } catch (DriverException $exception) {
            // Son attente dépassée sous son propre nom, pour que le journal
            // la distingue de celle de l'advisory lock ; tout autre refus
            // de la base remonte tel quel.
            if (PostgresLockedTransaction::LOCK_NOT_AVAILABLE === $exception->getSQLState()) {
                throw AccountAdministrationLockTimeoutException::rowHeld($targetId->toRfc4122(), $exception);
            }

            throw $exception;
        }

        if (null === $target) {
            $this->forgetStaleInstance($targetId);

            throw CpgUserNotFoundException::forId($targetId);
        }
    }

    /**
     * Relit l'acteur, sans verrouiller sa ligne (voir la classe) ; disparu ou
     * sans ROLE_SUPER, il n'administre plus rien.
     */
    private function rereadActingUser(Uuid $actingUserId): void
    {
        $actingUser = $this->reread($actingUserId, forUpdate: false);

        if (null === $actingUser) {
            $this->forgetStaleInstance($actingUserId);

            throw ActingAccountNoLongerSuperAdminException::deletedMeanwhile();
        }

        if (!\in_array(CpgUser::ROLE_SUPER, $actingUser->getRoles(), true)) {
            throw ActingAccountNoLongerSuperAdminException::demotedMeanwhile();
        }
    }

    /**
     * Une seule requête, qui rafraîchit sur place l'instance déjà gérée et
     * rend `null` si la ligne a disparu ; `FOR UPDATE` à la demande.
     */
    private function reread(Uuid $id, bool $forUpdate): ?CpgUser
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('account')
            ->from(CpgUser::class, 'account')
            ->andWhere('account.id = :id')
            ->setParameter('id', $id, UuidType::NAME)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true);
        if ($forUpdate) {
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        }

        /** @var CpgUser|null $user */
        $user = $query->getOneOrNullResult();

        return $user;
    }

    /**
     * La ligne a disparu : détache l'instance que la carte d'identité tient
     * encore (sans requête), sauf si une écriture l'attend — ce serait annuler
     * en silence son écriture en attente (le filtre de la relecture du verrou
     * d'ordre, #402).
     */
    private function forgetStaleInstance(Uuid $id): void
    {
        $unitOfWork = $this->entityManager->getUnitOfWork();
        $stale = $unitOfWork->tryGetById(['id' => $id], CpgUser::class);

        if (false !== $stale && !$unitOfWork->isScheduledForInsert($stale) && !$unitOfWork->isScheduledForDelete($stale)) {
            // Détachée et retenue : re-sauvée, elle serait réinsérée (#548).
            $this->vanishedEntities->detachVanished($this->entityManager, $stale);
        }
    }
}
