<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Domain\Service;

use App\Portfolio\Watch\Domain\ValueObject\PackageManifest;

/**
 * Donne accès au périmètre analysé : les paquets réellement déployés.
 *
 * Le `null` de retour n'est pas un cas d'erreur mais une réponse : aucun
 * manifeste n'a été produit, donc **aucune analyse n'a été tentée**. C'est une
 * information différente d'un manifeste vide, qui dirait qu'il n'y avait rien à
 * analyser. La page doit pouvoir distinguer les deux, sans quoi une
 * installation où l'analyse n'a jamais tourné afficherait un rassurant
 * « 0 vulnérabilité » parfaitement mensonger.
 */
interface PackageManifestReaderInterface
{
    public function read(): ?PackageManifest;
}
