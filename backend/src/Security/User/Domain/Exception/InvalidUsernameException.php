<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Exception;

use DomainException;

/**
 * Exception métier levée lorsqu'on tente de créer un CpgUser avec un nom
 * d'utilisateur ne respectant pas CpgUser::USERNAME_PATTERN.
 */
final class InvalidUsernameException extends DomainException
{
    public static function forUsername(string $username): self
    {
        return new self(\sprintf('Le nom d\'utilisateur "%s" est invalide : il doit contenir entre 3 et 60 caractères (lettres, chiffres, ".", "_" ou "-").', $username));
    }

    /**
     * Même règle, sans la saisie : pour un chemin où elle peut être n'importe
     * quoi (une invite console où un mot de passe a été collé par erreur) et
     * où le message peut finir dans un journal (issue #383).
     */
    public static function invalidFormat(): self
    {
        return new self('Le nom d\'utilisateur est invalide : il doit contenir entre 3 et 60 caractères (lettres, chiffres, ".", "_" ou "-").');
    }
}
