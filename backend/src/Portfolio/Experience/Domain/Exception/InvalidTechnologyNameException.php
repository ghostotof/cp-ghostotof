<?php

declare(strict_types=1);

namespace App\Portfolio\Experience\Domain\Exception;

use DomainException;

/**
 * Exception métier levée quand le nom d'une technologie, une fois rogné, est
 * vide ou dépasse sa colonne (issue #386).
 *
 * **Volontairement absente d'`exception_to_status` et de `framework.exceptions`**,
 * comme InvalidExperienceYearsException : le DTO backoffice refuse ces deux
 * cas avant tout Processor (`NotBlank` avec normaliseur `trim`, `Length`).
 * Depuis l'API, elle n'est atteignable que si un chemin d'écriture contourne
 * cette validation, un défaut serveur qui doit sortir en 500 `critical`. Seule
 * la commande CLI la rattrape, pour en afficher le message — qui ne cite pas
 * la saisie.
 */
final class InvalidTechnologyNameException extends DomainException
{
    public static function blank(): self
    {
        return new self('Le nom de la technologie ne peut pas être vide.');
    }

    public static function tooLong(int $maxLength): self
    {
        return new self(\sprintf('Le nom de la technologie ne doit pas dépasser %d caractères.', $maxLength));
    }
}
