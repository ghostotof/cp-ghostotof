<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Infrastructure\Manifest;

/**
 * Le répertoire où écrire un manifeste de la veille (relevé des versions
 * déployées, manifeste des paquets) n'a pas pu être créé (issue #338).
 *
 * Levée au `docker build` (ADR 0002), jamais sur le chemin d'une requête : elle
 * arrête la construction plutôt que de laisser une image sans manifeste. Le
 * message cite un chemin de build, sans secret. Partagée par les deux builders
 * du dossier, qui écrivent de la même façon.
 */
final class ManifestDirectoryCreationException extends \RuntimeException
{
    public static function for(string $directory): self
    {
        return new self(\sprintf('Impossible de créer le répertoire « %s ».', $directory));
    }
}
