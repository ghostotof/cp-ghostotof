<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Exception;

use DomainException;

/**
 * Exception métier levée lorsqu'on tente de créer un CpgUser avec un nom
 * d'utilisateur déjà utilisé par un autre compte.
 */
final class UsernameAlreadyUsedException extends DomainException
{
    public static function forUsername(string $username): self
    {
        return new self(\sprintf('Un utilisateur existe déjà avec le nom d\'utilisateur "%s".', $username));
    }

    /**
     * L'identifiant a été pris entre la vérification et l'enregistrement, et
     * la contrainte unique l'a refusé (issue #364). Ne cite pas l'identifiant :
     * dérivé d'une adresse, c'en est souvent la partie identifiante
     * (prénom.nom), et ce refus-là n'est rattrapé par aucun appelant HTTP —
     * il est journalisé (issue #356).
     */
    public static function takenMeanwhile(): self
    {
        return new self('Le nom d\'utilisateur a été pris par une autre écriture avant l\'enregistrement.');
    }
}
