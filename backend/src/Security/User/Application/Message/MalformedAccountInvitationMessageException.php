<?php

declare(strict_types=1);

namespace App\Security\User\Application\Message;

use UnexpectedValueException;

/**
 * Un SendAccountInvitationMessage désérialisé n'a pas la forme attendue
 * (champ absent, mal typé, identifiant ou langue invalide) : le message ne
 * vient pas de {@see \App\Security\User\Application\CpgUserInviter}, ou il a
 * été altéré dans la file.
 * {@see \Symfony\Component\Messenger\Transport\Serialization\PhpSerializer}
 * en fait un échec de décodage : le message n'atteint jamais le handler. Le
 * message de l'exception ne cite aucune valeur lue.
 */
final class MalformedAccountInvitationMessageException extends UnexpectedValueException
{
    public static function fromSerializedData(): self
    {
        return new self('Message d\'invitation désérialisé mal formé : userId (UUID), locale (langue du site) et invitationGeneration (entier ou absent) attendus.');
    }
}
