<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Infrastructure;

/**
 * Lecture défensive d'un tableau décodé depuis du JSON dont on ne maîtrise pas
 * la forme.
 *
 * Ce contexte en lit de trois provenances — la réponse d'endoflife.date, celle
 * d'OSV.dev, et le payload d'un `WatchSnapshot` relu en base — et leur applique
 * volontairement la même méfiance. Y compris au snapshot, pourtant écrit par
 * notre propre rafraîchisseur : ce qu'il contient vient d'un tiers, une entrée
 * mal formée doit faire disparaître cette entrée-là et pas la page entière.
 *
 * D'où la règle unique : une valeur absente, d'un autre type ou vide vaut
 * `null` — jamais une exception, jamais une valeur inventée. Elle était écrite
 * six fois à l'identique avant d'être rassemblée ici ; la changer (un `trim`,
 * un jour) ne doit demander qu'une seule modification.
 *
 * Trait plutôt que service : ce sont des fonctions pures sans dépendance, et
 * les six classes concernées sont déjà `final readonly` avec leurs propres
 * dépendances injectées — leur en ajouter une pour cela n'apporterait rien.
 * Cantonné à `Portfolio/Watch` tant qu'aucun autre contexte n'en a besoin :
 * c'est le seul dont les données viennent de l'extérieur.
 */
trait ReadsUntrustedArrays
{
    /**
     * @param array<mixed> $data
     */
    private function readString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return \is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * Les seules chaînes exploitables de la liste, dans l'ordre. Une valeur
     * qui n'est pas une liste vaut liste vide : l'absence d'information ne doit
     * pas se lire comme une erreur.
     *
     * @param array<mixed> $data
     *
     * @return list<string>
     */
    private function readStringList(array $data, string $key): array
    {
        $values = $data[$key] ?? null;

        if (!\is_array($values)) {
            return [];
        }

        $collected = [];

        foreach ($values as $value) {
            if (\is_string($value) && '' !== $value) {
                $collected[] = $value;
            }
        }

        return $collected;
    }
}
