<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\UnitOfWork;

/**
 * Relit, une fois un verrou pris, ce que l'EntityManager a chargé avant lui,
 * et détache ce dont la ligne a disparu (issue #560).
 *
 * Écrit une fois pour les verrous qui relisent — la technologie (#545),
 * l'administration des comptes (#551), la ligne d'un compte (#435, #458), le
 * périmètre d'ordre (#402) —, qui en portaient chacun une copie : un correctif
 * sur l'une n'aurait pas atteint les autres, et la ligne d'un compte, relue par
 * `SELECT 1 … FOR UPDATE` puis `refresh()`, ne détachait déjà pas l'instance
 * disparue. Le *pourquoi* de chaque relecture reste dans son verrou ; le
 * *comment* est ici.
 *
 * - **Une requête DQL avec `HINT_REFRESH`** : elle rafraîchit sur place
 *   l'instance déjà gérée (même objet, qu'un appelant peut garder et sauver),
 *   et ne rend pas une ligne disparue. Ni `refresh()` — il n'hydrate rien sur
 *   une ligne supprimée et garde l'instance —, ni `find($id, LockMode)`, qui
 *   sur une instance gérée n'est qu'un `refresh()` avec `FOR UPDATE`.
 * - **`FOR UPDATE` à la demande** ({@see self::row()}) : le verrou de ligne
 *   et la relecture en une seule requête.
 * - **Ligne disparue : détachée par {@see VanishedEntities::detachVanished()}**,
 *   seul point de détachement, qui refuse ensuite sa re-sauvegarde (#548) :
 *   Doctrine tiendrait l'instance pour neuve et réinsérerait la ligne.
 * - **Jamais une instance dont une écriture est en attente** (insertion ou
 *   suppression planifiée) : la relire n'a pas de sens, la détacher annulerait
 *   en silence son écriture (#402). Ce filtre n'est écrit qu'ici.
 *
 * L'EntityManager est un paramètre : celui du verrou, dont la transaction
 * porte la relecture — le service ne peut relire ailleurs que là où le verrou
 * est pris. Suppose READ COMMITTED,
 * le niveau par défaut : la première lecture après le verrou voit ce que son
 * détenteur précédent a validé.
 */
final readonly class RereadUnderLock
{
    public function __construct(private VanishedEntities $vanishedEntities)
    {
    }

    /**
     * Relit la ligne `$id` de `$class` ; `null` si elle a disparu (l'instance
     * périmée détachée) ou n'existe pas encore (insertion en attente, gardée).
     *
     * @template T of object
     *
     * @param class-string<T> $class
     * @param mixed           $id        l'identifiant, tel que l'entité le porte
     * @param bool            $forUpdate verrouille aussi la ligne (`FOR UPDATE`) pour la transaction en cours
     *
     * @return T|null
     */
    public function row(EntityManagerInterface $entityManager, string $class, mixed $id, bool $forUpdate): ?object
    {
        $metadata = $entityManager->getClassMetadata($class);
        $identifier = $metadata->getSingleIdentifierFieldName();

        $query = $entityManager->createQueryBuilder()
            ->select('entry')
            ->from($metadata->rootEntityName, 'entry')
            ->andWhere(\sprintf('entry.%s = :id', $identifier))
            ->setParameter('id', $id, $metadata->getTypeOfField($identifier) ?? Types::STRING)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true);
        if ($forUpdate) {
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        }

        // Aucun héritage n'est mappé : la racine est la classe, et une ligne
        // présente est une instance de `$class`. Le jour où une hiérarchie
        // l'est, une ligne d'une sous-classe sœur serait prise ici pour
        // disparue — à revoir alors.
        $entry = $query->getOneOrNullResult();
        if ($entry instanceof $class) {
            return $entry;
        }

        // Ligne absente : l'instance que la carte d'identité tient encore
        // (sans requête) est périmée, sauf si une écriture l'attend.
        $unitOfWork = $entityManager->getUnitOfWork();
        $stale = $unitOfWork->tryGetById([$identifier => $id], $metadata->rootEntityName);
        if (false !== $stale && !self::awaitsAWrite($unitOfWork, $stale)) {
            $this->vanishedEntities->detachVanished($entityManager, $stale);
        }

        return null;
    }

    /**
     * Relit en une requête toutes les instances de `$class` que l'EntityManager
     * gère : rafraîchies si la ligne existe encore, détachées sinon. Rien de
     * chargé, aucune requête ; une classe non mappée n'en a aucune.
     *
     * @param class-string $class
     */
    public function everyManagedInstanceOf(EntityManagerInterface $entityManager, string $class): void
    {
        if ($entityManager->getMetadataFactory()->isTransient($class)) {
            return;
        }

        $metadata = $entityManager->getClassMetadata($class);
        $unitOfWork = $entityManager->getUnitOfWork();
        $loaded = array_values(array_filter(
            $unitOfWork->getIdentityMap()[$metadata->rootEntityName] ?? [],
            static fn (object $entry): bool => !self::awaitsAWrite($unitOfWork, $entry),
        ));
        if ([] === $loaded) {
            return;
        }

        $identifier = $metadata->getSingleIdentifierFieldName();
        $identifierType = $metadata->getTypeOfField($identifier) ?? Types::STRING;
        $connection = $entityManager->getConnection();
        $ids = array_map(
            static fn (object $entry): mixed => $connection->convertToDatabaseValue($metadata->getIdentifierValues($entry)[$identifier], $identifierType),
            $loaded,
        );

        // Liée en chaînes : sur PostgreSQL, l'identifiant converti est le
        // texte de l'uuid natif, que la base compare à la colonne uuid.
        /** @var list<object> $reread la requête rend les instances déjà gérées, rafraîchies */
        $reread = $entityManager->createQueryBuilder()
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
                $this->vanishedEntities->detachVanished($entityManager, $entry);
            }
        }
    }

    /**
     * Une insertion ou une suppression attend le prochain `flush()` : ni à
     * relire ni à détacher, ce qui annulerait l'écriture en attente (#402).
     *
     * La branche « suppression » est défensive : `UnitOfWork::detach()` ne fait
     * déjà rien sur une instance `STATE_REMOVED` (mesuré pour #560, aucun test
     * ne peut donc la voir), mais `VanishedEntities` la retiendrait comme
     * disparue sous un verrou au lieu de laisser son `postRemove` dire la vraie
     * cause. Seule l'insertion en attente est épinglée par un test.
     */
    private static function awaitsAWrite(UnitOfWork $unitOfWork, object $entry): bool
    {
        return $unitOfWork->isScheduledForInsert($entry) || $unitOfWork->isScheduledForDelete($entry);
    }
}
