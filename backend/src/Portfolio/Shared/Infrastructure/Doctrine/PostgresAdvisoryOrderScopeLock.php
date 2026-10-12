<?php

declare(strict_types=1);

namespace App\Portfolio\Shared\Infrastructure\Doctrine;

use App\Portfolio\Shared\Application\OrderScopeLockInterface;
use App\Portfolio\Shared\Domain\Exception\TranslationGroupHasSeveralPositionsException;
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
 * Le squelette est celui des trois verrous PostgreSQL,
 * {@see PostgresLockedTransaction} (issue #512) : une transaction ouverte et
 * close à la main — ni `wrapInTransaction()`, qui fermerait l'EntityManager
 * sur un refus métier (409 « version déjà existante »), ni
 * `Connection::transactional()`, qui masquait un COMMIT refusé derrière
 * « There is no active transaction » (#390) —, l'attente bornée si la session
 * ne la borne pas, son `55P03` renommé en {@see OrderScopeLockTimeoutException}.
 * Ce verrou-ci y ajoute le nom du refus que le trigger différé des groupes de
 * traduction oppose au COMMIT : {@see TranslationGroupHasSeveralPositionsException},
 * avec le message de la base, qui nomme la table et le groupe. Sous FPM, le
 * délai reste le `lock_timeout` de 5 s de www.prod.conf (#272), et dans les
 * pods console (`app:*:seed`) celui de leur `PGOPTIONS` (#525) ; une console
 * qui n'en a aucun (dev, CI) reçoit celui du squelette, qui attendait sans
 * fin avant #512.
 *
 * **Il refuse de s'imbriquer** dans une transaction déjà ouverte, la sienne
 * comprise ({@see OrderScopeLockInsideTransactionException}, issue #526 ; la
 * garde vit dans le squelette depuis #532, commune aux trois verrous) :
 * imbriqué, son COMMIT ne serait qu'un `RELEASE SAVEPOINT`, le trigger
 * refuserait au COMMIT de l'appelant en `23T90` brut, et le verrou ne serait
 * relâché qu'alors. Refuser plutôt que documenter la limite : aucun appelant
 * n'imbrique (ni les `Administrator`s, ni les seeds, ni les migrations, qui
 * écrivent en SQL), et la règle « sur le chemin applicatif, le refus sort
 * sous le nom de l'invariant » tient ainsi par construction, au lieu de
 * reposer sur la mémoire du prochain appelant.
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
 * les dit introuvables. Pas `refresh()` entrée par entrée : il ne voit pas une
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
 * Restent hors de cette garantie les suppressions qui contournent les
 * `Administrator`s : la purge `--force` des `app:*:seed`, et le SQL.
 *
 * Coût : dans une commande qui enchaîne N opérations sur un même
 * EntityManager, chacune relit ce que les précédentes ont chargé — O(N²)
 * lignes, négligeable pour des seeds d'une dizaine d'entrées (même ordre que
 * `ContentPlacement::atEndOf()`).
 *
 * Cela suppose READ COMMITTED, le niveau par défaut de PostgreSQL, que rien ne
 * change ici : chaque instruction y voit ce qui a été validé avant elle, donc
 * la première lecture après le verrou voit l'écriture du détenteur précédent.
 * En REPEATABLE READ, l'instantané serait pris par le `SELECT` du verrou
 * lui-même, avant l'attente. `ScopeLockReadsCommittedStateTest` épingle les
 * deux.
 */
final readonly class PostgresAdvisoryOrderScopeLock implements OrderScopeLockInterface
{
    /**
     * Première clé du verrou, commune à tous les périmètres d'ordre : « ORDR »
     * en ASCII. La seconde est `hashtext()` du périmètre, le nom de la classe
     * d'entité qui porte les positions.
     */
    public const int ADVISORY_NAMESPACE = 0x4F524452;

    /**
     * SQLSTATE propre au projet, levé par le trigger différé
     * `trg_translation_group_single_position` (migration Version20261012120000,
     * issue #390) : classe 23 (violation d'intégrité), sous-classe hors de
     * celles que PostgreSQL définit. Le reconnaître ne demande pas de lire le
     * message.
     */
    public const string TRANSLATION_GROUP_ON_SEVERAL_POSITIONS = '23T90';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private string $fallbackLockTimeout = PostgresLockedTransaction::FALLBACK_LOCK_TIMEOUT,
    ) {
    }

    public function withLock(string $scope, Closure $operation): mixed
    {
        $connection = $this->entityManager->getConnection();

        return new PostgresLockedTransaction($connection, $this->logger, $this->fallbackLockTimeout)->run(
            // Bloquant : attend la fin de la transaction qui tient ce périmètre.
            acquire: static fn (): mixed => $connection->executeQuery(
                'SELECT pg_advisory_xact_lock(?, hashtext(?))',
                [self::ADVISORY_NAMESPACE, $scope],
            ),
            onTimeout: static fn (DriverException $timeout): OrderScopeLockTimeoutException => OrderScopeLockTimeoutException::forScope($scope, $timeout),
            // Issue #526 : imbriqué, le refus du trigger des groupes tomberait
            // au COMMIT de l'appelant, sans `onCommitRefused` pour le nommer.
            onNested: static fn (): OrderScopeLockInsideTransactionException => OrderScopeLockInsideTransactionException::forScope($scope),
            operation: function () use ($scope, $operation): mixed {
                $this->rereadEntriesLoadedBefore($scope);

                return $operation();
            },
            onCommitRefused: static fn (DriverException $refusal): ?TranslationGroupHasSeveralPositionsException => self::TRANSLATION_GROUP_ON_SEVERAL_POSITIONS === $refusal->getSQLState()
                ? TranslationGroupHasSeveralPositionsException::refusedAtCommit($refusal)
                : null,
        );
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
                $this->entityManager->detach($entry);
            }
        }
    }
}
