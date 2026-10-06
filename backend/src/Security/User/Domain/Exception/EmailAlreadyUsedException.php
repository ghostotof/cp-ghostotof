<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Exception;

/**
 * Exception métier levée lorsqu'on tente d'inviter un utilisateur avec une
 * adresse e-mail déjà rattachée à un compte existant.
 *
 * Le message ne cite jamais l'adresse (issue #356) : le noyau le journalise,
 * la préproduction tourne en LOG_LEVEL=debug, et API Platform le renvoie en
 * `detail`. Le backoffice n'en a pas besoin — il affiche son propre libellé
 * traduit (`email-taken`) à partir du statut 409, et connaît l'adresse
 * qu'il vient de saisir.
 */
final class EmailAlreadyUsedException extends \DomainException
{
    public static function alreadyLinkedToAnAccount(): self
    {
        return new self('Un utilisateur existe déjà avec cette adresse e-mail.');
    }
}
