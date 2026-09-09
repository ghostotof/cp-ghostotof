<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Domain\Service;

use App\Portfolio\Watch\Domain\ValueObject\VersionSource;

/**
 * Fournit la version réellement installée pour les sources résolues à
 * l'exécution (décision D2).
 *
 * L'abstraction existe pour que le domaine ne dépende pas de constantes
 * globales : c'est ce qui permet de substituer une version fixe en test.
 */
interface InstalledVersionResolverInterface
{
    /**
     * Le slug est nécessaire pour la source `DEPLOYED` : contrairement aux
     * sources runtime, qui désignent chacune un composant unique, elle couvre
     * plusieurs produits et il faut savoir lequel.
     *
     * @return string|null la version installée, ou null si la source ne relève
     *                     pas du runtime (cas `MANUAL`, dont la version est
     *                     stockée sur l'entité) ou si elle n'a pas pu être
     *                     relevée
     */
    public function resolve(VersionSource $source, string $slug): ?string;
}
