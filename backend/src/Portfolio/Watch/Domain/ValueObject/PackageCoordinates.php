<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Domain\ValueObject;

/**
 * Un paquet déployé, identifié comme la base de vulnérabilités l'attend.
 *
 * Les deux écosystèmes sont nommés d'après le vocabulaire d'OSV, seul endroit
 * où ces chaînes ont un sens : « Packagist » et non « Composer », « npm » en
 * minuscules. Les figer en constantes évite qu'une faute de casse ne rende une
 * requête muette — OSV répondrait sans erreur, mais sans résultat.
 */
final readonly class PackageCoordinates
{
    public const string ECOSYSTEM_PACKAGIST = 'Packagist';
    public const string ECOSYSTEM_NPM = 'npm';

    public function __construct(
        public string $ecosystem,
        public string $name,
        public string $version,
    ) {
    }
}
