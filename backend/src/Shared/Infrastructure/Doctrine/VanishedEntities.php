<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Stringable;
use WeakMap;

/**
 * Les entités dont la ligne a disparu, qu'un verrou a détachées : elles ne
 * reviennent pas en base (issue #548).
 *
 * Deux verrous détachent l'instance qu'ils trouvent périmée, ligne supprimée
 * entre son chargement et le verrou : la relecture du verrou d'ordre (#402,
 * {@see \App\Portfolio\Shared\Infrastructure\Doctrine\PostgresAdvisoryOrderScopeLock})
 * et le verrou de ligne d'une technologie (#545,
 * {@see \App\Portfolio\Experience\Infrastructure\Doctrine\PostgresExperienceTechnologyRowLock}).
 * Détachée, `find()` la dit introuvable — c'est le but. Mais un appelant qui
 * la tient encore et la sauve (`<Repository>::save()`, `persist()` puis
 * `flush()`) la faisait **réinsérer** : `UnitOfWork::doPersist()` tient pour
 * neuve une instance qu'il ne gère pas, sans lire la base, et la ligne
 * supprimée revenait, sans erreur ni trace. Aucun chemin ne le faisait (les
 * `Administrator`s relisent leur entrée sous le verrou et ne reçoivent que
 * des identifiants) ; la garde fait tenir la règle par construction, au lieu
 * de la mémoire du prochain appelant.
 *
 * Les verrous détachent par {@see self::detachVanished()}, qui retient
 * l'instance ; l'écouteur `prePersist` refuse ensuite son `persist()` par
 * {@see VanishedEntitySavedAgainException}, avant que rien ne soit planifié.
 * `prePersist` n'est émis que pour une instance que Doctrine tient pour neuve,
 * précisément le chemin de la réinsertion : une instance gérée ne le
 * déclenche pas. Aucun faux positif possible : la `WeakMap` retient des
 * objets, pas des identifiants ni des classes, et seul un objet passé par
 * `detachVanished()` y figure — jamais une entité neuve, ni une autre
 * instance de la même ligne.
 *
 * Une `WeakMap` : elle ne retient aucune instance en mémoire (un worker, une
 * commande qui enchaîne). L'écouteur vaut pour toutes les classes : un appel
 * par `persist()` d'une entité neuve, négligeable devant l'`INSERT` qu'il
 * précède, et aucune classe à déclarer quand un verrou de plus détachera.
 * Seule l'instance du conteneur écoute — celle que DoctrineBundle branche sur
 * l'EntityManager — : une instance construite à la main ne refuserait rien,
 * d'où les tests qui la prennent dans le conteneur.
 *
 * Hors de sa portée, accepté. Les entités que ces verrous détachent n'ont pas
 * d'association : un `detach()` en cascade toucherait des enfants que la garde
 * ne retient pas, à revoir le jour où l'une en porte. Une instance détachée
 * autrement — par `close()` (#502, #547, #549) ou `clear()` — n'est pas
 * retenue : sur le même EntityManager fermé, son `persist()` échoue déjà en
 * {@see \Doctrine\ORM\Exception\EntityManagerClosed} ; seul un EntityManager
 * réinitialisé l'accepterait, et aucun worker n'écrit d'entité qu'un verrou
 * détache.
 */
#[AsDoctrineListener(event: Events::prePersist)]
final readonly class VanishedEntities
{
    /**
     * Les entités détachées comme disparues, retenues par leur identité
     * d'objet.
     *
     * @var WeakMap<object, true>
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
        $this->vanished[$entity] = true;
        $entityManager->detach($entity);
    }

    /**
     * Appelé par l'EventManager de Doctrine pour chaque `persist()` d'une
     * instance tenue pour neuve.
     */
    public function prePersist(PrePersistEventArgs $event): void
    {
        $entity = $event->getObject();

        if (isset($this->vanished[$entity])) {
            throw VanishedEntitySavedAgainException::forEntity($entity::class, $this->identifierOf($event, $entity));
        }
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
