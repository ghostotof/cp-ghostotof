<?php

declare(strict_types=1);

namespace App\Portfolio\Shared\Infrastructure\Doctrine;

use App\Portfolio\Shared\Application\OrderScopeLockInterface;
use App\Shared\Infrastructure\Doctrine\VanishedEntities;
use App\Shared\Infrastructure\Lock\PostgresLockedTransaction;
use Closure;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Psr\Log\LoggerInterface;

/**
 * Verrou de périmètre d'ordre sur un advisory lock PostgreSQL **de
 * transaction** (issue #389).
 *
 * Pourquoi `pg_advisory_xact_lock` sur la connexion de l'ORM plutôt que
 * `lock.factory` (#272) : le verrou de `symfony/lock` est un verrou de session
 * sur une seconde connexion, qu'il faudrait relâcher *après* le COMMIT de
 * l'ORM — relâché avant, l'écriture concurrente relirait l'état d'avant. Le
 * verrou de transaction est relâché par le COMMIT ou le ROLLBACK eux-mêmes :
 * il ne peut ni précéder l'écriture visible, ni fuir. Pas d'état sur le pod
 * non plus (ADR 0005) : il vit dans la base, partagé par tous les pods.
 *
 * Forme à deux clés `int4` : PostgreSQL range ces verrous dans un espace
 * distinct des verrous à une clé `bigint` qu'utilise `symfony/lock`, aucune
 * collision n'est donc possible avec ceux des limiteurs de débit.
 *
 * Le squelette est celui des verrous PostgreSQL,
 * {@see PostgresLockedTransaction} (issue #512) : une transaction ouverte et
 * close à la main — ni `wrapInTransaction()`, qui fermerait l'EntityManager
 * sur un refus métier (409 « version déjà existante »), ni
 * `Connection::transactional()`, qui masquait un COMMIT refusé derrière
 * « There is no active transaction » (#390) —, l'attente bornée si la session
 * ne la borne pas, son `55P03` renommé en {@see OrderScopeLockTimeoutException}.
 * Ce verrou-ci y ajoute le nom des refus que le trigger différé des groupes de
 * traduction oppose au COMMIT, sous l'invariant que chacun dit, avec le
 * message de la base, qui nomme la table et le groupe. Il en **déclenche** la
 * traduction parce qu'il possède le COMMIT où tombe un refus différé ; la
 * correspondance SQLSTATE → invariant, ce que reçoit une écriture qui le
 * contourne et le middleware DBAL écarté sont décrits par
 * {@see TranslationGroupRefusal} (#506) — un nouvel invariant du trigger ne
 * touche plus ce verrou. Aucune écriture applicative capable de réveiller le
 * trigger ne se fait hors verrou (#389, `OrderScopeLockCoverageTest`). Sous FPM, le
 * délai reste le `lock_timeout` de 5 s de www.prod.conf (#272), et dans les
 * pods console (`app:*:seed`) celui de leur `PGOPTIONS` (#525) ; une console
 * qui n'en a aucun (dev, CI) reçoit celui du squelette, qui attendait sans
 * fin avant #512.
 *
 * **Il refuse de s'imbriquer** dans une transaction déjà ouverte, la sienne
 * comprise ({@see OrderScopeLockInsideTransactionException}, issue #526 ; la
 * garde vit dans le squelette depuis #532, commune à tous ses verrous) :
 * imbriqué, son COMMIT ne serait qu'un `RELEASE SAVEPOINT`, le trigger
 * refuserait au COMMIT de l'appelant en `23T90` ou `23T91` brut, et le verrou ne serait
 * relâché qu'alors. Refuser plutôt que documenter la limite : aucun appelant
 * n'imbrique (ni les `Administrator`s, ni les migrations, qui
 * écrivent en SQL), et la règle « sur le chemin applicatif, le refus sort
 * sous le nom de l'invariant » tient ainsi par construction, au lieu de
 * reposer sur la mémoire du prochain appelant.
 *
 * **Une réentrance, bornée** (issue #567) : `withLocks()` ouvre **une**
 * transaction par le squelette, y prend les verrous de plusieurs périmètres —
 * triés, pour que deux appels qui demandent les mêmes ne s'interbloquent
 * pas ; un par instruction, donc `lock_timeout` borne chaque attente et non
 * leur somme —, relit chacun (#402) et retient les périmètres tenus dans un
 * registre vidé quoi qu'il arrive. Sous cette transaction, un `withLock()`
 * de **ce même adaptateur** sur un périmètre **tenu** exécute son opération
 * directement : ni `BEGIN`, ni nouveau verrou, ni relecture — rien n'a pu
 * être validé dans un périmètre que cette transaction tient. Un simple
 * `withLock()` n'alimente pas le registre : sous lui, un `withLock()` du
 * même périmètre reste refusé, comme tout autre — la réentrance n'existe
 * que pour l'opération qui l'a demandée en prenant `withLocks()`. C'est ce qui
 * permet à un `app:*:seed` d'enchaîner les `Administrator`s, qui prennent
 * chacun `withLock()`, sous une seule transaction : sa purge et ses
 * recréations valident ou s'annulent ensemble, ce que le refus de #526
 * interdisait (#532 avait écarté la réentrance faute d'appelant). Elle ne
 * contredit pas la raison de #526 : le COMMIT reste celui du verrou
 * extérieur, donc `onCommitRefused` nomme toujours un refus différé, et la
 * fermeture de l'EntityManager (#547, #549) couvre toute la transaction.
 * Tout autre cas imbriqué reste refusé par le squelette, que la réentrance
 * ne traverse pas : un périmètre non tenu — un seed qui verrouille X pendant
 * que son `Administrator` écrit Y, une erreur de périmètre qui doit se voir
 * —, un `withLocks()` sous un verrou, une transaction ouverte par un autre
 * code. Le registre est celui de l'instance : une seule, celle du conteneur,
 * sert les `Administrator`s et les commandes.
 *
 * **Un COMMIT refusé par la base ferme l'EntityManager** (issue #502) : toute
 * `DriverException` levée par le COMMIT, quel que soit son SQLSTATE. C'est le
 * squelette qui le ferme depuis #547, pour tous ses verrous, avant
 * d'appeler `onCommitRefused`, qui ne fait plus ici que nommer les refus du
 * trigger des groupes ({@see TranslationGroupRefusal::nameOf()}).
 * Les `flush()` de l'opération ont réussi au point de sauvegarde ; le refus annule ce qu'ils
 * ont écrit, mais l'UnitOfWork le tient pour écrit : une lecture sur le même
 * EntityManager rendait la position refusée, `find()` une entrée jamais
 * validée, et une écriture hors verrou repartait de là. Il est fermé comme
 * l'échec du COMMIT de Doctrine lui-même le ferme
 * ({@see \Doctrine\ORM\UnitOfWork::commit()}) : sa carte d'identité est vidée,
 * une **nouvelle** lecture relit la base, une écriture par l'ORM échoue en
 * {@see \Doctrine\ORM\Exception\EntityManagerClosed}. Ce que l'appelant tient
 * déjà garde les valeurs refusées (détaché), et une instruction DBAL brute ou
 * un `UPDATE` DQL passe encore — aucun dépôt du Portfolio n'écrit ainsi. Une
 * session perdue au COMMIT (`ConnectionLost`, une `DriverException` aussi)
 * laisse son issue inconnue : fermer reste la réponse sûre. Un refus métier
 * (409, 404) est levé par l'opération et laisse l'EntityManager ouvert, parce
 * que chaque `Administrator` le lève **avant** toute écriture ; levé après un
 * `flush()` qui a écrit, il le ferme, comme toute exception de l'opération
 * (issue #549, le squelette encore) : le ROLLBACK aurait laissé le même état
 * périmé. Un worker Messenger le retrouve neuf au message
 * suivant ({@see \Doctrine\Bundle\DoctrineBundle\Registry::reset()}
 * réinitialise un EntityManager fermé) ; une commande s'arrête sur
 * l'exception. Un COMMIT que DBAL refuse lui-même avant d'écrire
 * (`CommitFailedRollbackOnly`, après un `setRollbackOnly()` que rien
 * n'appelle ici) n'est pas une `DriverException` et laisse l'EntityManager
 * ouvert. Rejetés :
 * relire le seul périmètre sur le refus (une requête de plus sur le chemin
 * d'échec, à protéger pour ne pas masquer le refus, la seule classe du
 * périmètre couverte, et des écritures qui reprennent après un invariant
 * cassé) ; `clear()` seul, qui laisserait l'EntityManager écrire — `close()`
 * vide la carte d'identité de la même façon, toutes classes confondues, mais
 * refuse ensuite toute écriture par l'ORM.
 *
 * Ce que l'EntityManager a chargé **avant** le verrou est relu une fois le
 * verrou pris (issue #402) : `find()` rendrait l'instance de la carte
 * d'identité, et une requête ne réhydrate pas une entité déjà gérée. Or le
 * provider d'un `PUT` charge l'entrée avant le processor, et une commande qui
 * enchaîne les opérations (`app:*:seed`) garde tout le périmètre chargé : une
 * écriture concurrente validée entre-temps était défaite sans le dire — un
 * rattachement vu comme un non-geste, un détachement sauté, une version créée
 * à la position que son groupe quittait, une entrée supprimée « mise à jour »
 * en 200. Les instances gérées de la classe du périmètre sont donc relues, en
 * une requête : celles que la base porte encore sont rafraîchies sur place
 * (même objet, qu'un appelant peut continuer de tenir et de sauver), celles
 * qu'elle ne rend plus — supprimées entre-temps — sont détachées, et `find()`
 * les dit introuvables. Celles-là, un appelant ne peut **pas** les sauver de
 * nouveau : Doctrine les tiendrait pour neuves et les réinsérerait, d'où le
 * détachement par {@see VanishedEntities}, qui refuse leur `persist()`
 * (issue #548). Pas `refresh()` entrée par entrée : il ne voit pas une
 * ligne supprimée (il n'hydrate rien et garde l'instance), et coûte une
 * requête par entrée. Pas de détachement de tout le périmètre non plus : une
 * instance tenue d'avant l'opération, sauvée ensuite, entrerait en collision
 * avec celle que l'opération aurait rechargée. Ce qui en découle pour
 * l'appelant : une modification non écrite d'une de ces instances est
 * écrasée — d'où le corps entier de chaque opération dans `withLock()`. Une
 * entrée persistée mais pas encore écrite, ou à supprimer, n'est ni relue ni
 * détachée : son écriture en attente part avec le `flush()` de l'opération.
 *
 * Une suppression validée **pendant** l'opération lui échapperait : l'`UPDATE`
 * d'un `PUT` ne toucherait aucune ligne, et le `PUT` répondrait 200 sur une
 * entrée disparue. C'est pourquoi `delete()` prend aussi le verrou (#499) : une
 * suppression passe entièrement avant l'opération, qui la dit introuvable, ou
 * entièrement après, et le perdant de deux `DELETE` simultanés est en 404.
 * La purge `--force` des `app:*:seed` est sous ce verrou aussi depuis #567 ;
 * reste hors de cette garantie la suppression en SQL.
 *
 * Coût : dans une commande qui enchaîne N opérations sur un même
 * EntityManager, chacune relisait ce que les précédentes avaient chargé —
 * O(N²) lignes. Sous `withLocks()`, la relecture n'a lieu qu'une fois, à
 * l'entrée (même ordre que `ContentPlacement::atEndOf()`).
 *
 * Cela suppose READ COMMITTED, le niveau par défaut de PostgreSQL, que rien ne
 * change ici : chaque instruction y voit ce qui a été validé avant elle, donc
 * la première lecture après le verrou voit l'écriture du détenteur précédent.
 * En REPEATABLE READ, l'instantané serait pris par le `SELECT` du verrou
 * lui-même, avant l'attente. `ScopeLockReadsCommittedStateTest` épingle les
 * deux.
 */
final class PostgresAdvisoryOrderScopeLock implements OrderScopeLockInterface
{
    /**
     * Première clé du verrou, commune à tous les périmètres d'ordre : « ORDR »
     * en ASCII. La seconde est `hashtext()` du périmètre, le nom de la classe
     * d'entité qui porte les positions.
     */
    public const int ADVISORY_NAMESPACE = 0x4F524452;

    /**
     * Les périmètres que la transaction ouverte par `withLocks()` tient, le
     * temps de son opération (issue #567) : vide hors d'elle. Le seul état
     * mutable de l'adaptateur, d'où la classe non `readonly`.
     *
     * @var list<class-string>
     */
    private array $heldScopes = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly VanishedEntities $vanishedEntities,
        private readonly string $fallbackLockTimeout = PostgresLockedTransaction::FALLBACK_LOCK_TIMEOUT,
    ) {
    }

    public function withLock(string $scope, Closure $operation): mixed
    {
        // Issue #567 : sous withLocks() de cet adaptateur, un périmètre déjà
        // tenu participe à la transaction ouverte, sans rien reprendre ni
        // relire. Tout autre cas passe par le squelette, qui refuse
        // l'imbrication — un withLock() n'alimente pas le registre.
        if (\in_array($scope, $this->heldScopes, true)) {
            return $operation();
        }

        return $this->locked(
            [$scope],
            $operation,
            static fn (): OrderScopeLockInsideTransactionException => OrderScopeLockInsideTransactionException::forScope($scope),
            registered: false,
        );
    }

    /**
     * @param list<class-string> $scopes plus large que le `non-empty-list` de l'interface,
     *                                   pour que le refus d'une liste vide construite à
     *                                   l'exécution soit un vrai contrôle
     */
    public function withLocks(array $scopes, Closure $operation): mixed
    {
        // Triés et dédoublonnés : deux appels qui demandent les mêmes
        // périmètres les prennent dans le même ordre, donc sans interblocage.
        $scopes = array_values(array_unique($scopes));
        sort($scopes, \SORT_STRING);

        if ([] === $scopes) {
            throw OrderScopeLockWithoutScopeException::noScopeGiven();
        }

        return $this->locked(
            $scopes,
            $operation,
            static fn (): OrderScopeLockInsideTransactionException => OrderScopeLockInsideTransactionException::forScopes($scopes),
            registered: true,
        );
    }

    /**
     * La transaction du squelette, ses verrous pris dans l'ordre reçu, chaque
     * périmètre relu (#402), puis l'opération.
     *
     * @param non-empty-list<class-string>                   $scopes
     * @param Closure(): OrderScopeLockInsideTransactionException $onNested
     * @param bool                                           $registered vrai pour `withLocks()` seul : ses périmètres
     *                                                                   sont retenus le temps de l'opération, et un
     *                                                                   `withLock()` de l'un d'eux y participe (#567)
     */
    private function locked(array $scopes, Closure $operation, Closure $onNested, bool $registered): mixed
    {
        // Le périmètre dont l'attente est en cours : c'est lui que nomme un
        // délai dépassé. Capturé par référence des deux côtés — une fonction
        // fléchée le figerait à sa création.
        $waitingFor = $scopes[0];

        return new PostgresLockedTransaction($this->entityManager, $this->logger, $this->fallbackLockTimeout)->run(
            acquire: function () use ($scopes, &$waitingFor): void {
                $this->acquireEachOf($scopes, $waitingFor);
            },
            onTimeout: static function (DriverException $timeout) use (&$waitingFor): OrderScopeLockTimeoutException {
                return OrderScopeLockTimeoutException::forScope($waitingFor, $timeout);
            },
            // Issue #526 : imbriqué, le refus du trigger des groupes tomberait
            // au COMMIT de l'appelant, sans `onCommitRefused` pour le nommer.
            onNested: $onNested,
            operation: fn (): mixed => $this->holding($scopes, $operation, $registered),
            // Le nom des refus du trigger des groupes (#506, voir plus haut).
            onCommitRefused: TranslationGroupRefusal::nameOf(...),
        );
    }

    /**
     * Prend le verrou de chaque périmètre, dans l'ordre reçu (trié) : bloquant,
     * attend la fin de la transaction qui tient chacun. Un verrou par
     * instruction, parce que l'ordre d'évaluation d'une liste `SELECT` n'est
     * pas garanti ; `lock_timeout` borne donc **chaque** attente, pas leur
     * somme — N périmètres, jusqu'à N fois la borne.
     *
     * @param non-empty-list<class-string> $scopes
     * @param class-string                 $waitingFor reçoit le périmètre en cours d'acquisition
     */
    private function acquireEachOf(array $scopes, string &$waitingFor): void
    {
        $connection = $this->entityManager->getConnection();

        foreach ($scopes as $scope) {
            $waitingFor = $scope;
            $connection->executeQuery(
                'SELECT pg_advisory_xact_lock(?, hashtext(?))',
                [self::ADVISORY_NAMESPACE, $scope],
            );
        }
    }

    /**
     * Sous les verrous pris : relit chaque périmètre (#402), puis exécute
     * l'opération — pour `withLocks()`, en retenant les périmètres tenus,
     * oubliés quelle que soit l'issue : un registre périmé ferait tourner le
     * prochain `withLock()` hors de toute transaction.
     *
     * @param non-empty-list<class-string> $scopes
     */
    private function holding(array $scopes, Closure $operation, bool $registered): mixed
    {
        foreach ($scopes as $scope) {
            $this->rereadEntriesLoadedBefore($scope);
        }

        if (!$registered) {
            return $operation();
        }

        $this->heldScopes = $scopes;

        try {
            return $operation();
        } finally {
            $this->heldScopes = [];
        }
    }

    /**
     * Relit en base les instances de `$scope` que l'EntityManager gère déjà
     * (issue #402, voir plus haut) : rafraîchies si la ligne existe encore,
     * détachées sinon. Rien à relire, aucune requête — le cas d'un `POST`.
     *
     * @param class-string $scope
     */
    private function rereadEntriesLoadedBefore(string $scope): void
    {
        // Une classe non mappée n'a aucune instance gérée : rien à relire.
        if ($this->entityManager->getMetadataFactory()->isTransient($scope)) {
            return;
        }

        $metadata = $this->entityManager->getClassMetadata($scope);
        $unitOfWork = $this->entityManager->getUnitOfWork();
        // Seules les instances lues en base se relisent. Une entrée à insérer
        // n'y est pas encore (son UUID, donné par le constructeur, la met dans
        // la carte d'identité dès persist()) et une entrée à supprimer n'est
        // plus à relire : les détacher annulerait l'écriture en attente.
        $loaded = array_values(array_filter(
            $unitOfWork->getIdentityMap()[$metadata->rootEntityName] ?? [],
            static fn (object $entry): bool => !$unitOfWork->isScheduledForInsert($entry) && !$unitOfWork->isScheduledForDelete($entry),
        ));
        if ([] === $loaded) {
            return;
        }

        $identifier = $metadata->getSingleIdentifierFieldName();
        $identifierType = $metadata->getTypeOfField($identifier) ?? Types::STRING;
        $connection = $this->entityManager->getConnection();
        $ids = array_map(
            static fn (object $entry): mixed => $connection->convertToDatabaseValue($metadata->getIdentifierValues($entry)[$identifier], $identifierType),
            $loaded,
        );

        // Liée en chaînes : sur PostgreSQL, l'identifiant converti est le
        // texte de l'uuid natif, que la base compare à la colonne uuid.
        /** @var list<object> $reread la requête rend les instances déjà gérées, rafraîchies */
        $reread = $this->entityManager->createQueryBuilder()
            ->select('entry')
            ->from($metadata->rootEntityName, 'entry')
            ->andWhere(\sprintf('entry.%s IN (:ids)', $identifier))
            ->setParameter('ids', $ids, ArrayParameterType::STRING)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        $stillStored = array_flip(array_map(spl_object_id(...), $reread));
        foreach ($loaded as $entry) {
            if (!isset($stillStored[spl_object_id($entry)])) {
                // Détachée et retenue : re-sauvée, elle serait réinsérée (#548).
                $this->vanishedEntities->detachVanished($this->entityManager, $entry);
            }
        }
    }
}
