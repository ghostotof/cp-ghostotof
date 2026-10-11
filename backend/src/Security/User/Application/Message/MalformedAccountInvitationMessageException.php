<?php

declare(strict_types=1);

namespace App\Security\User\Application\Message;

use UnexpectedValueException;

/**
 * Un SendAccountInvitationMessage désérialisé n'a pas la forme attendue
 * (champ absent, mal typé, identifiant ou langue invalide, génération
 * inférieure à 1) : le message ne vient pas de
 * {@see \App\Security\User\Application\CpgUserInviter}, ou il a été altéré
 * dans la file. Un message sans génération, le format d'avant l'issue #434,
 * a sa propre fabrique (issue #448) : il appelle une relance, pas une
 * enquête.
 * {@see \Symfony\Component\Messenger\Transport\Serialization\PhpSerializer}
 * en fait un échec de décodage : le message n'atteint jamais le handler. Le
 * message de l'exception ne cite aucune valeur lue.
 */
final class MalformedAccountInvitationMessageException extends UnexpectedValueException
{
    public static function fromSerializedData(): self
    {
        return new self('Message d\'invitation désérialisé mal formé : userId (UUID), locale (langue du site) et invitationGeneration (entier, au moins 1) attendus.');
    }

    /**
     * Le format d'avant l'issue #434, que seule une image v0.22.0 ou
     * antérieure produit. Le message dit à l'opérateur quoi faire de
     * l'entrée qu'il trouve dans `failed` : la rejouer échouerait de même.
     */
    public static function withoutGeneration(): self
    {
        return new self('Message d\'invitation sans génération, au format d\'avant la v0.23.0 : relancer l\'invitation depuis le backoffice, puis retirer ce message de failed (messenger:failed:remove), sans le rejouer.');
    }
}
