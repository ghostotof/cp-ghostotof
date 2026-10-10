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
    /**
     * Jamais la saisie : elle peut être n'importe quoi (une invite console où
     * un mot de passe a été collé par erreur), et le message d'une exception
     * finit dans un journal (issues #383, #386). La variante qui la citait,
     * `forUsername()`, a disparu avec son dernier appelant, le constructeur
     * de CpgUser.
     */
    public static function invalidFormat(): self
    {
        return new self('Le nom d\'utilisateur est invalide : il doit contenir entre 3 et 60 caractères (lettres, chiffres, ".", "_" ou "-").');
    }
}
