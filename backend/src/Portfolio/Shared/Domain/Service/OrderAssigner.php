<?php

declare(strict_types=1);

namespace App\Portfolio\Shared\Domain\Service;

use App\Portfolio\Shared\Domain\Exception\IncompleteOrderException;
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
     * @throws UnknownOrderEntryException une clé de la liste n'est pas dans le périmètre, ou y
     *                                    apparaît plus d'une fois
     * @throws IncompleteOrderException   une clé du périmètre est absente de la liste
     */
    public function assign(iterable $scope, array $orderedKeys): void
    {
        $entitiesByKey = [];

        foreach ($scope as $entity) {
            $entitiesByKey[$entity->orderingKey()][] = $entity;
        }

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
}
