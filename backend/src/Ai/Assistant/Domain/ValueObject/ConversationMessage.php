<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Domain\ValueObject;

use App\Ai\Assistant\Domain\Exception\InvalidConversationException;

/**
 * Un message de la conversation : son auteur et un contenu non vide, de
 * longueur bornée (spec 0005 D6). La borne d'un message de l'assistant est
 * plus large : c'est une réponse renvoyée par le frontend, que `max_tokens`
 * (1 024 jetons) peut rendre longue. Longueurs en caractères, pas en octets.
 */
final readonly class ConversationMessage
{
    public const int MAX_USER_LENGTH = 1000;
    public const int MAX_ASSISTANT_LENGTH = 4000;

    public function __construct(
        public Role $role,
        public string $content,
    ) {
        if ('' === trim($content)) {
            throw new InvalidConversationException('Un message de la conversation est vide.');
        }

        $maxLength = Role::User === $role ? self::MAX_USER_LENGTH : self::MAX_ASSISTANT_LENGTH;
        if (mb_strlen($content) > $maxLength) {
            throw new InvalidConversationException('Un message de la conversation est trop long.');
        }
    }
}
