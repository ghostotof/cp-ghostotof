<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Domain\ValueObject;

/**
 * La liste des paquets déployés, telle qu'elle a été relevée au moment du
 * build.
 *
 * `generatedAt` n'est pas décoratif : le manifeste est figé dans l'image, alors
 * que l'analyse de vulnérabilités, elle, est rejouée chaque jour. Savoir de
 * quand date le périmètre analysé fait partie de ce que la page doit pouvoir
 * dire honnêtement.
 *
 * Un manifeste **vide** est un manifeste : il dit « rien à analyser ». Son
 * absence dirait « analyse jamais tentée ». Les deux ne doivent jamais être
 * confondus, d'où un objet plutôt qu'un simple tableau.
 */
final readonly class PackageManifest
{
    /**
     * @param list<PackageCoordinates> $packages
     */
    public function __construct(
        public \DateTimeImmutable $generatedAt,
        public array $packages,
    ) {
    }
}
