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
 * Les verrous détachent par {@see self::forget()}, qui retient l'instance ;
 * l'écouteur `prePersist` refuse ensuite son `persist()` par
 * {@see VanishedEntitySavedAgainException}, avant que rien ne soit planifié.
 * `prePersist` n'est émis que pour une instance que Doctrine tient pour neuve,
 * précisément le chemin de la réinsertion : une instance gérée ne le
 * déclenche pas. Aucun faux positif possible : l'identifiant d'une entité lui
 * est donné par son constructeur, une entité neuve n'est donc jamais une
 * instance retenue ici.
 *
 * Une `WeakMap` : elle ne retient aucune instance en mémoire (un worker, une
 * commande qui enchaîne), et survit au `reset()` d'un EntityManager fermé, le
 * service restant le même. Hors de sa portée, accepté : une instance détachée
 * autrement — par `close()` (#502, #547, #549) ou `clear()` — n'est pas
 * retenue. Sur le même EntityManager fermé, son `persist()` échoue déjà en
 * {@see \Doctrine\ORM\Exception\EntityManagerClosed} ; seul un EntityManager
 * réinitialisé l'accepterait, et aucun worker n'écrit d'entité qu'un verrou
 * détache.
 */
#[AsDoctrineListener(event: Events::prePersist)]
final readonly class VanishedEntities
{
    /**
     * L'entité détachée, et sa désignation (classe et identifiant) relevée
     * pendant qu'elle était encore gérée.
     *
     * @var WeakMap<object, string>
     */
    private WeakMap $forgotten;

    public function __construct()
    {
        $this->forgotten = new WeakMap();
    }

    /**
     * Détache l'entité dont la ligne a disparu, et refusera sa re-sauvegarde.
     */
    public function forget(EntityManagerInterface $entityManager, object $entity): void
    {
        $identifier = array_map(
            static fn (mixed $value): string => \is_scalar($value) || $value instanceof Stringable ? (string) $value : get_debug_type($value),
            $entityManager->getClassMetadata($entity::class)->getIdentifierValues($entity),
        );
        $this->forgotten[$entity] = implode(', ', $identifier);

        $entityManager->detach($entity);
    }

    /**
     * Appelé par l'EventManager de Doctrine pour chaque `persist()` d'une
     * instance tenue pour neuve.
     */
    public function prePersist(PrePersistEventArgs $event): void
    {
        $entity = $event->getObject();

        if (isset($this->forgotten[$entity])) {
            throw VanishedEntitySavedAgainException::forEntity($entity::class, $this->forgotten[$entity]);
        }
    }
}
