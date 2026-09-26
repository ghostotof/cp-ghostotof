<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Domain\ValueObject;

use App\Ai\Assistant\Domain\Exception\InvalidConversationException;

/** Un message de la conversation : son auteur et un contenu non vide. */
final readonly class ConversationMessage
{
    public function __construct(
        public Role $role,
        public string $content,
    ) {
        if ('' === trim($content)) {
            throw new InvalidConversationException('Un message de la conversation est vide.');
        }
    }
}
