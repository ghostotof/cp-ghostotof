<?php

declare(strict_types=1);

namespace App\Ai\Translation\Domain\Exception;

/**
 * Requête de traduction incohérente (locales identiques, dictionnaire vide,
 * valeur blanche). Le DTO d'entrée la prévient par validation (422) : si elle
 * remonte jusqu'ici, c'est un défaut de programmation, pas une saisie.
 */
final class InvalidTranslationRequestException extends \InvalidArgumentException
{
    public static function identicalLocales(string $locale): self
    {
        return new self(\sprintf('La locale cible doit différer de la locale source ("%s").', $locale));
    }

    public static function emptyDictionary(): self
    {
        return new self('Aucun champ à traduire.');
    }

    public static function blankValue(string $field): self
    {
        return new self(\sprintf('Le champ "%s" est vide.', $field));
    }
}
