<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Un fichier du contexte de build qu'un stage copie dans la configuration de
 * PHP ou de FPM (issue #616), lu par {@see PhpImageBuild::configurationCopiesOf()}.
 * Le stage fait partie de la copie : deux stages qui écrivent la même
 * destination sont deux copies, et le garde lit les deux.
 */
final readonly class ConfigurationCopy
{
    /** Le répertoire que PHP balaie au démarrage (`--with-config-file-scan-dir`). */
    private const string PHP_SCAN_DIRECTORY = '/usr/local/etc/php/conf.d/';

    public function __construct(
        public string $stage,
        public string $source,
        public string $destination,
    ) {
    }

    /**
     * Le fichier est un ini que PHP charge : directement dans `conf.d/` (PHP
     * ne descend pas dans ses sous-répertoires), suffixé `.ini`.
     */
    public function isLoadedPhpIni(): bool
    {
        return \dirname($this->destination).'/' === self::PHP_SCAN_DIRECTORY && str_ends_with($this->destination, '.ini');
    }
}
