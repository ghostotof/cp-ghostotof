<?php

declare(strict_types=1);

namespace App\Portfolio\Shared\Domain\ValueObject;

use App\Portfolio\Shared\Domain\Exception\InvalidLocaleException;

/**
 * Langue du contenu portfolio géré en base (About/Quality/Stats/Experience).
 * Miroir du type `Locale` côté frontend (frontend/src/domain/portfolio/entities/Locale.ts).
 */
enum Locale: string
{
    case FR = 'fr';
    case EN = 'en';

    /**
     * Variante de `from()` pour les valeurs venues de l'extérieur (segment
     * d'URL, argument de commande…) : lève une exception métier explicite
     * plutôt qu'un `\ValueError` générique.
     *
     * Point d'audit I3 : permet à `api_platform.yaml` de mapper précisément
     * `InvalidLocaleException` sur 404, au lieu du fourre-tout `ValueError: 404`
     * qui déguisait aussi en 404 toute autre ValueError du projet.
     *
     * @throws InvalidLocaleException si la valeur ne correspond à aucune langue gérée
     */
    public static function fromString(string $value): self
    {
        return self::tryFrom($value) ?? throw InvalidLocaleException::forValue($value);
    }
}
