<?php

declare(strict_types=1);

namespace App\Security\User\Application\Message;

use UnexpectedValueException;

/**
 * Un SendAccountInvitationMessage désérialisé n'a pas la forme attendue
 * (champ absent ou mal typé) : le message vient d'ailleurs que de
 * CpgUserInviter, ou il a été altéré dans la file. PhpSerializer en fait un
 * échec de décodage : le message n'atteint jamais le handler. Le message de
 * l'exception ne cite aucune valeur lue.
 */
final class MalformedAccountInvitationMessageException extends UnexpectedValueException
{
    public static function fromSerializedData(): self
    {
        return new self('Message d\'invitation désérialisé mal formé : userId et locale (chaînes), invitedAt (entier ou absent) attendus.');
    }
}
