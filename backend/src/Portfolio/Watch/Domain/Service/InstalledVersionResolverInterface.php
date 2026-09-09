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
     * @return string|null la version installée, ou null si la source ne relève
     *                     pas du runtime (cas `MANUAL`, dont la version est
     *                     stockée sur l'entité)
     */
    public function resolve(VersionSource $source): ?string;
}
