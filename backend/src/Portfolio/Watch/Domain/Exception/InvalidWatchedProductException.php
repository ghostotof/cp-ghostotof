<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Domain\Exception;

use App\Portfolio\Watch\Domain\ValueObject\VersionSource;

/**
 * Exception métier levée quand la version d'un produit surveillé contredit sa
 * source de version (décision D2) : une entrée saisie à la main sans version,
 * ou une entrée résolue au runtime à laquelle on tente d'imposer une valeur.
 */
final class InvalidWatchedProductException extends \DomainException
{
    public static function manualVersionRequired(string $slug): self
    {
        return new self(sprintf(
            'Le produit "%s" a une version saisie manuellement : cette version est obligatoire.',
            $slug,
        ));
    }

    public static function runtimeVersionMustNotBeProvided(string $slug, VersionSource $source): self
    {
        return new self(sprintf(
            'La version du produit "%s" est déterminée à l\'exécution (%s) : elle ne peut pas être saisie.',
            $slug,
            $source->value,
        ));
    }
}
