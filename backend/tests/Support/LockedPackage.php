<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Un paquet de `composer.lock`, tel que {@see ComposerFiles::lockedPackages()}
 * le lit : la section qui l'installe, son nom, sa version telle que le lock
 * l'écrit (le tag, `v` compris) et les paquets qu'il exige.
 */
final readonly class LockedPackage
{
    /**
     * @param 'packages'|'packages-dev' $section  `packages` (production) ou `packages-dev`
     * @param list<string>              $requires les noms des paquets de son `require`
     */
    public function __construct(
        public string $section,
        public string $name,
        public string $version,
        public array $requires,
    ) {
    }

    /** La version sans le `v` d'un tag (`v0.15.0` -> `0.15.0`), comme `composer.json` l'écrit. */
    public function versionWithoutTagPrefix(): string
    {
        return str_starts_with($this->version, 'v') ? substr($this->version, 1) : $this->version;
    }
}
