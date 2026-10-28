<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * La famille d'API Platform dans un `composer.lock` (issue #701) : les paquets
 * `api-platform/*` que les racines tirent, et la version de chacun.
 *
 * Tout paquet préfixé `api-platform/` n'est pas du monorepo `api-platform/core`
 * (le bundle et ses composants, sortis ensemble sous un même tag) : l'outil de
 * génération de schémas, par exemple, a ses propres versions. Rien dans le lock
 * ne distingue les deux — `source.url` est un dépôt `api-platform/<nom>` dans les
 * deux cas, `homepage` manque à l'un, `extra.branch-alias` se copie — ; ce qui
 * les distingue est le **graphe des dépendances** : la famille est ce que les
 * racines (les paquets `api-platform/*` que `composer.json` exige) tirent, de
 * proche en proche, parmi les `api-platform/*`. Un paquet qu'aucune racine ne
 * tire n'est pas de la famille, quel que soit son nom ; un composant que le
 * bundle tirera demain en sera sans que le test change. Une liste de noms
 * écrite à la main ferait l'inverse : elle ignorerait le nouveau composant.
 *
 * Limite : un paquet `api-platform/*` hors monorepo qu'une racine exige
 * entrerait dans la famille — c'est alors une dépendance du bundle, et ce qui
 * est tiré ensemble doit l'être à la même version.
 */
final class ApiPlatformLockFamily
{
    private const string PREFIX = 'api-platform/';

    /**
     * @param array<mixed> $lock  le contenu décodé de `composer.lock`
     * @param list<string> $roots les paquets `api-platform/*` que `composer.json` exige
     *
     * @return array<string, string> la version par nom de paquet, dans l'ordre du lock
     */
    public static function versions(array $lock, array $roots): array
    {
        /** @var array<string, array{version: string, require: list<string>}> $packages */
        $packages = [];
        foreach (['packages', 'packages-dev'] as $section) {
            $entries = $lock[$section] ?? [];
            if (!\is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if (!\is_array($entry) || !\is_string($entry['name'] ?? null) || !\is_string($entry['version'] ?? null)) {
                    continue;
                }

                $require = \is_array($entry['require'] ?? null) ? array_keys($entry['require']) : [];
                $packages[$entry['name']] = ['version' => $entry['version'], 'require' => array_values(array_filter($require, \is_string(...)))];
            }
        }

        $family = [];
        $pending = array_values(array_filter($roots, static fn (string $name): bool => str_starts_with($name, self::PREFIX)));
        while ([] !== $pending) {
            $name = array_shift($pending);
            if (isset($family[$name]) || !isset($packages[$name])) {
                continue;
            }

            $family[$name] = true;
            foreach ($packages[$name]['require'] as $required) {
                if (str_starts_with($required, self::PREFIX)) {
                    $pending[] = $required;
                }
            }
        }

        $versions = [];
        foreach ($packages as $name => $package) {
            if (isset($family[$name])) {
                $versions[$name] = $package['version'];
            }
        }

        return $versions;
    }
}
