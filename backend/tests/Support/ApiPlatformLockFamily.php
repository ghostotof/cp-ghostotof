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
 * Limite : un paquet `api-platform/*` hors monorepo qu'une racine tire, ou que
 * `composer.json` exige lui-même (`require-dev` compris : c'est une racine),
 * entrerait dans la famille. Tiré par le bundle, il doit être à la version des
 * autres ; exigé par le projet, c'est le test qui dit qu'on l'y a mis.
 */
final class ApiPlatformLockFamily
{
    private const string PREFIX = 'api-platform/';

    /**
     * Les racines : les paquets `api-platform/*` que `composer.json` exige, dans
     * `require` ou `require-dev`. Aucune liste écrite à la main : un composant
     * exigé demain entre dans la famille sans que le test change.
     *
     * @param array<mixed> $composer le contenu décodé de `composer.json`
     *
     * @return list<string>
     */
    public static function roots(array $composer): array
    {
        $roots = [];
        foreach (['require', 'require-dev'] as $section) {
            $requirements = $composer[$section] ?? [];
            if (!\is_array($requirements)) {
                continue;
            }

            foreach (array_keys($requirements) as $name) {
                if (\is_string($name) && str_starts_with($name, self::PREFIX)) {
                    $roots[] = $name;
                }
            }
        }

        return array_values(array_unique($roots));
    }

    /**
     * @param array<mixed> $lock  le contenu décodé de `composer.lock`
     * @param list<string> $roots les paquets `api-platform/*` que `composer.json` exige ({@see self::roots()})
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
