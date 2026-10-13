<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Stringable;
use WeakMap;

/**
 * Les entités dont la ligne n'existe plus : elles ne reviennent pas en base
 * (issues #548, #557).
 *
 * Deux chemins mènent une instance hors de l'EntityManager alors que
 * l'appelant la tient encore, et tous deux la faisaient **réinsérer** s'il la
 * sauvait (`<Repository>::save()`, `persist()` puis `flush()`) :
 * `UnitOfWork::doPersist()` tient pour neuve une instance qu'il ne gère pas,
 * sans lire la base, et la ligne supprimée revenait au même UUID — nos
 * identifiants sont assignés au constructeur, Doctrine ne les efface pas —,
 * sans erreur ni trace.
 *
 * - **Disparue sous un verrou** (#548). Trois verrous détachent l'instance
 *   qu'ils trouvent périmée, ligne supprimée entre son chargement et le
 *   verrou : la relecture du verrou d'ordre (#402,
 *   {@see \App\Portfolio\Shared\Infrastructure\Doctrine\PostgresAdvisoryOrderScopeLock}),
 *   le verrou de ligne d'une technologie (#545,
 *   {@see \App\Portfolio\Experience\Infrastructure\Doctrine\PostgresExperienceTechnologyRowLock})
 *   et le verrou d'administration des comptes (#551, #552,
 *   {@see \App\Security\User\Infrastructure\Doctrine\PostgresAccountAdministrationLock}).
 *   Ils détachent par {@see self::detachVanished()}, jamais `detach()`.
 *   Détachée, `find()` la dit introuvable — c'est le but.
 * - **Supprimée par ce même EntityManager** (#557) : `remove()` puis
 *   `flush()`. `UnitOfWork::executeDeletions()` efface tout l'état de
 *   l'instance après le `DELETE` ; l'écouteur `postRemove`, émis à la fin de
 *   ces suppressions (pendant le `flush()`, avant le COMMIT), la retient.
 *   Ou par une requête DQL par identifiant (#569,
 *   `PasswordSetupTokenRepository::remove()`,
 *   `CpgUserRepository::removePendingInvitation()`) : elle n'émet pas
 *   `postRemove` et laissait l'instance **gérée**, si bien qu'une
 *   modification puis un `save()` émettaient un `UPDATE` sur 0 ligne, perdu
 *   en silence (Doctrine ne lit ce compte que pour une entité versionnée).
 *   Le dépôt passe l'instance à {@see self::detachDeleted()} quand son
 *   `DELETE` a touché la ligne, et seulement alors.
 *
 * Aucun chemin ne re-sauvait ces instances (les `Administrator`s relisent
 * leur entrée sous le verrou et ne reçoivent que des identifiants, les seeds
 * créent des instances neuves) : la garde fait tenir la règle par
 * construction, au lieu de la mémoire du prochain appelant.
 *
 * L'écouteur `prePersist` refuse ensuite le `persist()` d'une instance
 * retenue par {@see VanishedEntitySavedAgainException}, sous la fabrique de
 * sa cause, avant que rien ne soit planifié. `prePersist` n'est émis que pour
 * une instance que Doctrine tient pour neuve, précisément le chemin de la
 * réinsertion : une instance gérée ne le déclenche pas. Aucun faux positif :
 * la `WeakMap` retient des objets, pas des identifiants ni des classes, et
 * seul un objet détaché comme disparu ou dont le `DELETE` a été exécuté y
 * figure — jamais une entité neuve, ni une autre instance de la même ligne.
 * `remove()` puis `persist()` **avant** le `flush()` n'est pas touché : la
 * suppression est annulée (`STATE_REMOVED` redevient géré, sans
 * `prePersist`) et rien n'est retenu, `postRemove` n'ayant pas été émis.
 *
 * **Restaurer une entité en re-persistant l'instance supprimée est refusé,
 * sans échappatoire** : une restauration crée une nouvelle entité. Le refus
 * vaut pour toutes les classes, comme `prePersist`.
 *
 * Une `WeakMap` : elle ne retient aucune instance en mémoire (un worker, une
 * commande qui enchaîne ou supprime en boucle). Les écouteurs valent pour
 * toutes les classes : un appel par `persist()` d'une entité neuve ou par
 * entité supprimée, négligeable devant l'`INSERT` ou le `DELETE` qu'il
 * accompagne, et aucune classe à déclarer quand un verrou de plus détachera.
 * Seule l'instance du conteneur écoute — celle que DoctrineBundle branche sur
 * l'EntityManager — : une instance construite à la main ne refuserait rien,
 * d'où les tests qui la prennent dans le conteneur.
 *
 * Hors de sa portée, accepté. Aucune association des entités détachées ici ne
 * porte de `cascade: ['detach']` (le jeton a un `ManyToOne` vers son compte,
 * sans cascade : le compte reste géré) : un `detach()` en cascade toucherait
 * des entités que la garde ne retient pas, à revoir le jour où l'une en porte.
 * L'effet inverse existe : une entité encore gérée qui **référence** l'instance
 * détachée (un jeton chargé dont le compte est détaché par
 * `removePendingInvitation()`) fait échouer le `flush()` suivant en
 * `ORMInvalidArgumentException` (« A new entity was found through the
 * relationship… » : `UnitOfWork::computeAssociationChanges()` tient pour
 * neuve une instance qu'il ne gère pas). Un échec bruyant, pas une perte, et
 * aucun chemin ne charge de jeton avant ce retrait (#569). Une instance détachée
 * autrement — par `close()` (#502, #547, #549) ou `clear()` — n'est pas
 * retenue : sur le même EntityManager fermé, son `persist()` échoue déjà en
 * {@see \Doctrine\ORM\Exception\EntityManagerClosed} ; seul un EntityManager
 * réinitialisé l'accepterait, et aucun worker n'écrit d'entité qu'un verrou
 * détache. Un ROLLBACK après le `flush()` qui supprimait laisse l'instance
 * retenue alors que la ligne est revenue. Le squelette des verrous
 * ({@see \App\Shared\Infrastructure\Lock\PostgresLockedTransaction}, #547,
 * #549) ferme alors l'EntityManager quand il voit ce ROLLBACK : exception de
 * l'opération après une écriture, COMMIT refusé par la base. Restent ouverts
 * un COMMIT refusé par DBAL lui-même (`CommitFailedRollbackOnly`, après un
 * `setRollbackOnly()`) et une transaction ouverte et annulée à la main : sans
 * la garde, le `persist()` de l'instance y heurterait au `flush()` la clé
 * primaire existante ; elle le refuse avant, sous un message qui croit la
 * ligne encore supprimée. Une entité à identifiant **généré** serait refusée à tort —
 * `executeDeletions()` remet son identifiant à null, son `persist()` serait
 * un `INSERT` neuf — : aucune n'en a (UUID v7 assignés au constructeur), à
 * revoir le jour où l'une en aura. Une suppression DQL **en masse**
 * (`PasswordSetupTokenRepository::deleteForUser()`, `deleteUnusedForUser()`),
 * une suppression en SQL et un `ON DELETE CASCADE` ne détachent rien : leurs
 * instances encore en mémoire restent gérées et ne repassent pas par
 * `prePersist` — aucun de ces chemins n'en a de chargée (#569). Une
 * suppression DQL par identifiant dont la ligne avait déjà disparu (0 ligne
 * touchée) ne détache pas non plus : rien ne dit alors si la ligne vit
 * encore. Et `detachDeleted()` appelé dans une transaction ensuite annulée
 * retient une instance dont la ligne est revenue — même limite que le
 * ROLLBACK après un `flush()` ci-dessus, aggravée de ce que le squelette des
 * verrous ne voit pas une écriture DQL (`WrittenFlushWatch`) et laisse
 * l'EntityManager ouvert ; les deux appelants suppriment hors de toute
 * transaction, une fois le verrou rendu.
 */
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::postRemove)]
final readonly class VanishedEntities
{
    /**
     * Les entités dont la ligne n'existe plus, retenues par leur identité
     * d'objet, avec la cause qui le dit.
     *
     * @var WeakMap<object, VanishedEntityCause>
     */
    private WeakMap $vanished;

    public function __construct()
    {
        $this->vanished = new WeakMap();
    }

    /**
     * Détache l'entité dont la ligne a disparu, et refusera sa re-sauvegarde.
     */
    public function detachVanished(EntityManagerInterface $entityManager, object $entity): void
    {
        $this->vanished[$entity] = VanishedEntityCause::VanishedUnderLock;
        $entityManager->detach($entity);
    }

    /**
     * Détache l'entité dont ce même EntityManager vient de supprimer la ligne
     * par une requête DQL (issue #569), et refusera sa re-sauvegarde sous la
     * cause « supprimée par cet EntityManager ».
     *
     * Le dépôt qui a exécuté le `DELETE` l'appelle **seulement si la requête a
     * touché la ligne** : une ligne épargnée par la condition (jeton consommé,
     * compte activé entre-temps) existe encore, et son instance reste gérée.
     */
    public function detachDeleted(EntityManagerInterface $entityManager, object $entity): void
    {
        $this->vanished[$entity] = VanishedEntityCause::RemovedByThisManager;
        $entityManager->detach($entity);
    }

    /**
     * Appelé par l'EventManager de Doctrine pour chaque entité dont le
     * `DELETE` vient d'être exécuté par `flush()` : retient l'instance, que
     * Doctrine tiendrait désormais pour neuve.
     */
    public function postRemove(PostRemoveEventArgs $event): void
    {
        $this->vanished[$event->getObject()] = VanishedEntityCause::RemovedByThisManager;
    }

    /**
     * Appelé par l'EventManager de Doctrine pour chaque `persist()` d'une
     * instance tenue pour neuve.
     */
    public function prePersist(PrePersistEventArgs $event): void
    {
        $entity = $event->getObject();

        $cause = $this->vanished[$entity] ?? null;
        if (null === $cause) {
            return;
        }

        $id = $this->identifierOf($event, $entity);

        throw match ($cause) {
            VanishedEntityCause::VanishedUnderLock => VanishedEntitySavedAgainException::vanishedUnderLock($entity::class, $id),
            VanishedEntityCause::RemovedByThisManager => VanishedEntitySavedAgainException::removedByThisManager($entity::class, $id),
        };
    }

    /**
     * L'identifiant de l'entité, en texte pour le message : ses métadonnées
     * le lisent aussi sur une instance détachée.
     */
    private function identifierOf(PrePersistEventArgs $event, object $entity): string
    {
        return implode(', ', array_map(
            static fn (mixed $value): string => \is_scalar($value) || $value instanceof Stringable ? (string) $value : get_debug_type($value),
            $event->getObjectManager()->getClassMetadata($entity::class)->getIdentifierValues($entity),
        ));
    }
}
