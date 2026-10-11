<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Security\User\Application\Message\SendAccountInvitationMessage;

/**
 * SendAccountInvitationMessage sous la forme que PhpSerializer écrit dans
 * RabbitMQ ou le `failure_transport` (issue #434) : un objet aux propriétés
 * publiques, sérialisé tel quel.
 */
final class SerializedInvitationMessage
{
    /**
     * @param string $properties les propriétés déjà sérialisées, clé puis valeur
     */
    public static function of(int $propertyCount, string $properties): string
    {
        return \sprintf(
            'O:%d:"%s":%d:{%s}',
            \strlen(SendAccountInvitationMessage::class),
            SendAccountInvitationMessage::class,
            $propertyCount,
            $properties,
        );
    }
}
