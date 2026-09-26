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
 * appel (spec 0005 D4) : forme et types ici, bornes de coût D6 (nombre de
 * messages, longueurs, alternance) dans le VO Conversation, dont la violation
 * est un 422 `/errors/invalid-conversation`.
 *
 * `Sequentially` partout où une contrainte suivante supposerait le type : sans
 * lui, NotBlank(normalizer: trim) sur un tableau serait une TypeError, donc un
 * 500 au lieu d'un 422.
 *
 * Le nombre de messages est vérifié avant chacun d'eux (audit de la tâche 3,
 * F6) : un corps de 128 Kio porte ~4 000 messages minuscules, que `All`
 * parcourait tous (~113 ms mesurés) avant que le VO n'en refuse plus de 11.
 * Le plafond reste volontairement large : une conversation simplement trop
 * longue garde le 422 typé `/errors/invalid-conversation` du VO, seul un
 * corps absurde reçoit ici le 422 de validation.
 */
final class AnswerRequest
{
    public const int MAX_MESSAGES_VALIDATED = 50;

    /**
     * @param array<mixed> $messages
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(callback: [Locale::class, 'values'])]
        public string $locale = '',
        #[Assert\Sequentially([
            new Assert\Count(min: 1, max: self::MAX_MESSAGES_VALIDATED),
            new Assert\All([
                new Assert\Sequentially([
                    new Assert\Type('array'),
                    new Assert\Collection(fields: [
                        'role' => new Assert\Sequentially([new Assert\Type('string'), new Assert\Choice(callback: [Role::class, 'values'])]),
                        'content' => new Assert\Sequentially([new Assert\Type('string'), new Assert\NotBlank(normalizer: 'trim')]),
                    ]),
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
