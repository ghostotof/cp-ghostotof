<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Infrastructure\Manifest;

use RuntimeException;

/**
 * Un manifeste de la veille (relevé des versions déployées, manifeste des
 * paquets) n'a pas pu être écrit : son répertoire n'a pas pu être créé, ou le
 * fichier lui-même n'a pas pu s'écrire (issue #338).
 *
 * Levée au `docker build` (ADR 0002), jamais sur le chemin d'une requête : elle
 * arrête la construction plutôt que de laisser partir une image sans manifeste,
 * que /api/watch présenterait sans bruit comme « non analysé ». Le message cite
 * un chemin de build, sans secret.
 */
final class ManifestWriteException extends RuntimeException
{
    public static function forDirectory(string $directory): self
    {
        return new self(\sprintf('Impossible de créer le répertoire « %s ».', $directory));
    }

    public static function forFile(string $path): self
    {
        return new self(\sprintf('Impossible d\'écrire le fichier « %s ».', $path));
    }
}
