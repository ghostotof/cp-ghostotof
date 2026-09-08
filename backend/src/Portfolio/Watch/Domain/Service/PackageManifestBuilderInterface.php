<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Domain\Service;

use App\Portfolio\Watch\Domain\ValueObject\PackageManifest;

/**
 * Relève le périmètre déployé et le fige là où l'application le relira.
 *
 * L'abstraction laisse la porte ouverte à une autre source que les fichiers de
 * verrouillage — un SBOM produit par la chaîne de build, par exemple — et rend
 * surtout la commande testable sans toucher au disque.
 */
interface PackageManifestBuilderInterface
{
    public function build(\DateTimeImmutable $generatedAt): PackageManifest;
}
