<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Domain\Service;

use App\Portfolio\Watch\Domain\ValueObject\ProductReleaseCycles;
use App\Portfolio\Watch\Domain\ValueObject\ReleaseCycle;

/**
 * Rapproche une version installée du cycle de vie auquel elle appartient, et
 * dit si un correctif plus récent existe sur ce cycle.
 *
 * Toute la difficulté tient en deux pièges numériques, l'un et l'autre couverts
 * par un test :
 *
 *  - « 8.10.2 » commence par la chaîne « 8.1 » sans appartenir au cycle 8.1 ;
 *    un `str_starts_with` afficherait donc « fin de vie » pour une version
 *    parfaitement supportée. Le rapprochement se fait segment par segment.
 *  - « 8.5.9 » est *antérieur* à « 8.5.10 », alors qu'une comparaison de
 *    chaînes conclurait l'inverse. D'où `version_compare`.
 */
final readonly class InstalledVersionMatcher
{
    public function matchInstalledVersion(
        ProductReleaseCycles $product,
        string $installedVersion,
    ): ?ReleaseCycle {
        $installed = $this->numericSegments($installedVersion);
        if ([] === $installed) {
            return null;
        }

        foreach ($product->cycles as $cycle) {
            $cycleSegments = $this->numericSegments($cycle->name);

            if ([] === $cycleSegments || \count($cycleSegments) > \count($installed)) {
                continue;
            }

            if (\array_slice($installed, 0, \count($cycleSegments)) === $cycleSegments) {
                return $cycle;
            }
        }

        return null;
    }

    public function hasNewerPatch(?ReleaseCycle $cycle, string $installedVersion): bool
    {
        if (null === $cycle || null === $cycle->latestVersion) {
            return false;
        }

        return version_compare($this->normalize($installedVersion), $cycle->latestVersion, '<');
    }

    /**
     * Découpe une version en ses segments numériques de tête, en s'arrêtant au
     * premier segment non numérique. Les versions d'image Docker traînent un
     * suffixe de distribution (`8.5.9-fpm-alpine3.24`) qu'il faut tolérer,
     * sinon la moitié de la stack serait rapportée « inconnue ».
     *
     * @return list<int>
     */
    private function numericSegments(string $version): array
    {
        $segments = [];

        foreach (explode('.', $version) as $part) {
            if (1 !== preg_match('/^(\d+)/', $part, $matches)) {
                break;
            }

            $segments[] = (int) $matches[1];
        }

        return $segments;
    }

    private function normalize(string $version): string
    {
        $segments = $this->numericSegments($version);

        return [] === $segments ? $version : implode('.', $segments);
    }
}
