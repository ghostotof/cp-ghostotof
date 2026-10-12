<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Exception;

use DomainException;

/**
 * Exception métier levée lorsqu'on tente de créer un CpgUser avec un nom
 * d'utilisateur déjà utilisé par un autre compte.
 *
 * Message constant, qui ne cite aucun compte (issue #363) : le noyau et API
 * Platform le journalisent, et l'identifiant d'un invité est dérivé de son
 * adresse. `app:user:create` rappelle lui-même à l'opérateur l'identifiant
 * qu'il vient de saisir.
 */
final class UsernameAlreadyUsedException extends DomainException
{
    public static function alreadyTaken(): self
    {
        return new self('Un utilisateur existe déjà avec ce nom d\'utilisateur.');
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
