<?php

declare(strict_types=1);

namespace App\Portfolio\Shared\Domain\Service;

use App\Portfolio\Shared\Domain\Exception\IncompleteOrderException;
use App\Portfolio\Shared\Domain\Exception\TranslationGroupHasSeveralPositionsException;
use App\Portfolio\Shared\Domain\Exception\UnknownOrderEntryException;
use App\Portfolio\Shared\Domain\Orderable;

/**
 * Spec 0004 D4/D5 : une seule logique d'ordre pour les neuf ressources qui la
 * partagent (les huit contenus localisés, groupés par traduction, et
 * `WatchedProduct`, groupé par id).
 *
 * Service pur : il ne connaît ni Doctrine ni API Platform, reçoit des
 * `Orderable` déjà chargés et n'en persiste aucun — c'est l'`Administrator`
 * appelant qui décide du périmètre à charger (la table entière, une
 * catégorie…) et de la transaction qui écrit le résultat.
 *
 * La règle d'ensemble exact (D4) n'est pas qu'une validation : si une entrée a
 * été créée ou supprimée entre le chargement de la page et l'enregistrement,
 * le périmètre relu ici et la liste envoyée par le frontend diffèrent, et ce
 * service le refuse — un contrôle de concurrence optimiste sans version ni
 * horodatage.
 *
 * Il ne répare pas un groupe de traduction déjà divergent (issue #503) : il
 * écrit la même position sur tous les membres d'une clé, ce qui réalignait en
 * silence un groupe resté sur deux positions malgré le trigger différé
 * (écriture triggers désactivés, SQL concurrent hors du verrou de périmètre) et
 * effaçait la preuve du défaut amont. Il le refuse, avant toute écriture et
 * avant les 422 du client. Il lève sans journaliser : c'est un service de
 * domaine pur, la sévérité `critical` vient de l'exception non mappée.
 */
final readonly class OrderAssigner
{
    /**
     * Vérifie que `$orderedKeys` est exactement l'ensemble des clés portées
     * par `$scope`, puis écrit l'index `0…n-1` de chaque clé sur **toutes**
     * les entités qui la portent — un groupe de traduction à deux locales
     * reçoit ainsi la même position pour ses deux entités.
     *
     * @param iterable<Orderable> $scope       les entités du périmètre, et seulement elles : une
     *                                         entité que l'appelant n'a pas mise dans ce périmètre
     *                                         n'est jamais vue, donc jamais touchée
     * @param list<string>        $orderedKeys l'ordre voulu, une occurrence par clé du périmètre
     *
     * @throws TranslationGroupHasSeveralPositionsException une clé du périmètre porte déjà plusieurs positions
     *                                                      (défaut du pipeline, contrôlé en premier)
     * @throws UnknownOrderEntryException                   une clé de la liste n'est pas dans le périmètre, ou y
     *                                                      apparaît plus d'une fois
     * @throws IncompleteOrderException                     une clé du périmètre est absente de la liste
     */
    public function assign(iterable $scope, array $orderedKeys): void
    {
        $entitiesByKey = [];

        foreach ($scope as $entity) {
            $entitiesByKey[$entity->orderingKey()][] = $entity;
        }

        $this->refuseKeysOnSeveralPositions($entitiesByKey);

        $consumedKeys = [];

        foreach ($orderedKeys as $key) {
            if (isset($consumedKeys[$key]) || !isset($entitiesByKey[$key])) {
                throw UnknownOrderEntryException::forKey($key);
            }

            $consumedKeys[$key] = true;
        }

        $missingKeys = array_values(array_diff(array_keys($entitiesByKey), array_keys($consumedKeys)));

        if ([] !== $missingKeys) {
            throw IncompleteOrderException::forKeys($missingKeys);
        }

        foreach ($orderedKeys as $position => $key) {
            foreach ($entitiesByKey[$key] as $entity) {
                $entity->moveToPosition($position);
            }
        }
    }

    /**
     * Toutes les entités d'une clé doivent déjà partager une position (spec
     * 0004 D3). Ce contrôle passe **avant** la règle d'ensemble exact, comme
     * celui de `ContentPlacement::inGroup()` avant le 409 (issue #384) : un
     * défaut du pipeline avant une erreur du client, sinon un 422 ordinaire
     * (une liste périmée, journalisée en `info`) le masquerait, et seul l'envoi
     * suivant, une fois la page rechargée, le nommerait. En mémoire, O(n), sans
     * requête : le périmètre est déjà chargé par l'appelant, sous son verrou.
     *
     * @param array<array-key, list<Orderable>> $entitiesByKey
     *
     * @throws TranslationGroupHasSeveralPositionsException
     */
    private function refuseKeysOnSeveralPositions(array $entitiesByKey): void
    {
        foreach ($entitiesByKey as $key => $entities) {
            $position = $entities[0]->getPosition();

            foreach ($entities as $entity) {
                if ($entity->getPosition() !== $position) {
                    throw TranslationGroupHasSeveralPositionsException::forOrderingKey(get_debug_type($entity), (string) $key, $this->distinctPositionsOf($entities));
                }
            }
        }
    }

    /**
     * Les positions distinctes d'une clé, croissantes : lues seulement pour le
     * message du refus, jamais sur le chemin nominal.
     *
     * @param list<Orderable> $entities
     *
     * @return list<int>
     */
    private function distinctPositionsOf(array $entities): array
    {
        $positions = array_values(array_unique(array_map(static fn (Orderable $entity): int => $entity->getPosition(), $entities)));
        sort($positions);

        return $positions;
    }
}
