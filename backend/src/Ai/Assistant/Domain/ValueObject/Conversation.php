<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Domain\ValueObject;

use App\Ai\Assistant\Domain\Exception\InvalidConversationException;

/**
 * Conversation envoyée par la personne, dans l'ordre reçu. Rien n'en est
 * conservé côté serveur (spec 0005 D7) : le frontend la renvoie à chaque tour.
 *
 * Bornes de coût (D6) : au plus MAX_MESSAGES messages, alternance stricte,
 * premier et dernier message de la personne. Le compte est donc toujours
 * impair, et la borne l'est aussi pour rester atteignable : 11, soit six
 * questions et cinq réponses (D6 amendée, la spec disait 12).
 */
final readonly class Conversation implements \Countable
{
    public const int MAX_MESSAGES = 11;

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

        if (\count($messages) > self::MAX_MESSAGES) {
            throw new InvalidConversationException('La conversation compte trop de messages.');
        }

        // Rôle attendu à chaque rang : la personne aux rangs pairs, l'assistant
        // aux impairs — premier message et alternance d'un même geste.
        foreach ($messages as $rank => $message) {
            if ($message->role !== (0 === $rank % 2 ? Role::User : Role::Assistant)) {
                throw new InvalidConversationException("La conversation n'alterne pas la personne et l'assistant.");
            }
        }

        if (Role::User !== $messages[array_key_last($messages)]->role) {
            throw new InvalidConversationException('La conversation doit se terminer par une question.');
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
