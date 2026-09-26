<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Domain\ValueObject;

use App\Ai\Assistant\Domain\Exception\InvalidConversationException;

/**
 * Conversation envoyée par la personne, dans l'ordre reçu. Rien n'en est
 * conservé côté serveur (spec 0005 D7) : le frontend la renvoie à chaque tour.
 */
final readonly class Conversation implements \Countable
{
    /** @var non-empty-list<ConversationMessage> */
    private array $messages;

    /**
     * @param list<ConversationMessage> $messages
     */
    public function __construct(array $messages)
    {
        if ([] === $messages) {
            throw new InvalidConversationException('La conversation est vide.');
        }

        $this->messages = $messages;
    }

    /** @return non-empty-list<ConversationMessage> */
    public function messages(): array
    {
        return $this->messages;
    }

    public function count(): int
    {
        return \count($this->messages);
    }
}
