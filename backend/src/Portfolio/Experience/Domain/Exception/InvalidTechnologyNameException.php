<?php

declare(strict_types=1);

namespace App\Portfolio\Experience\Domain\Exception;

use DomainException;

/**
 * Exception métier levée quand le nom d'une technologie, une fois rogné, est
 * vide, dépasse sa colonne, porte un caractère de contrôle ou n'est pas de
 * l'UTF-8 valide (issue #386).
 *
 * **Volontairement absente d'`exception_to_status` et de `framework.exceptions`**,
 * comme InvalidExperienceYearsException. Le DTO backoffice la convertit en violation sur `name` (Assert\Callback) :
 * depuis l'API, elle n'est atteignable que si un chemin d'écriture contourne
 * cette validation, un défaut serveur qui doit sortir en 500 `critical`. La
 * commande CLI la rattrape pour en afficher le message — qui ne cite jamais
 * la saisie.
 */
final class InvalidTechnologyNameException extends DomainException
{
    public static function blank(): self
    {
        return new self('Le nom de la technologie ne peut pas être vide.');
    }

    public static function controlCharacter(): self
    {
        return new self('Le nom de la technologie ne doit contenir aucun caractère de contrôle.');
    }

    public static function notUtf8(): self
    {
        return new self('Le nom de la technologie n\'est pas de l\'UTF-8 valide.');
    }

    public static function tooLong(int $maxLength): self
    {
        return new self(\sprintf('Le nom de la technologie ne doit pas dépasser %d caractères.', $maxLength));
    }
}
