<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Presentation\Dto;

use App\Ai\Assistant\Domain\Exception\InvalidConversationException;
use App\Ai\Assistant\Domain\ValueObject\Conversation;
use App\Ai\Assistant\Domain\ValueObject\ConversationMessage;
use App\Ai\Assistant\Domain\ValueObject\Role;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de POST /api/assistant/answers, validé par le Validator avant tout
 * appel (spec 0005 D4). Les bornes de coût D6 (nombre de messages, longueurs,
 * alternance) arrivent avec la tâche 3 (#262), dans le VO Conversation.
 *
 * `Sequentially` partout où une contrainte suivante supposerait le type : sans
 * lui, NotBlank(normalizer: trim) sur un tableau serait une TypeError, donc un
 * 500 au lieu d'un 422.
 */
final class AnswerRequest
{
    /**
     * @param array<mixed> $messages
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(callback: [Locale::class, 'values'])]
        public string $locale = '',
        #[Assert\Count(min: 1)]
        #[Assert\All([
            new Assert\Sequentially([
                new Assert\Type('array'),
                new Assert\Collection(fields: [
                    'role' => new Assert\Sequentially([new Assert\Type('string'), new Assert\Choice(callback: [Role::class, 'values'])]),
                    'content' => new Assert\Sequentially([new Assert\Type('string'), new Assert\NotBlank(normalizer: 'trim')]),
                ]),
            ]),
        ])]
        public array $messages = [],
    ) {
    }

    public function toConversation(): Conversation
    {
        $messages = [];
        foreach ($this->messages as $message) {
            // Forme garantie par les contraintes ci-dessus ; la vérification la
            // rend lisible par PHPStan et refuse un appel qui aurait sauté la validation.
            if (!\is_array($message) || !\is_string($message['role'] ?? null) || !\is_string($message['content'] ?? null)) {
                throw new InvalidConversationException('Un message de la conversation est mal formé.');
            }

            $messages[] = new ConversationMessage(Role::from($message['role']), $message['content']);
        }

        return new Conversation($messages);
    }
}
