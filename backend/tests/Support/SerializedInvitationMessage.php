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
     * Le format d'avant #434, sans génération.
     */
    public static function withoutGeneration(string $userId, string $locale = 'fr'): string
    {
        return self::of(2, \sprintf('s:6:"userId";s:%d:"%s";s:6:"locale";s:%d:"%s";', \strlen($userId), $userId, \strlen($locale), $locale));
    }

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
