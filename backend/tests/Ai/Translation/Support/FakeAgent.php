<?php

declare(strict_types=1);

namespace App\Tests\Ai\Translation\Support;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Execution\Execution;
use Symfony\AI\Agent\Execution\Update\Result as ResultUpdate;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\ResultInterface;

/**
 * Agent de test : renvoie un résultat préparé (ou lève une exception) et
 * enregistre ce qu'il a reçu, pour que les tests vérifient le message envoyé
 * au modèle et les options (schéma de sortie structurée) sans réseau. Le
 * MockAgent du composant indexe ses réponses par texte d'entrée exact, ce qui
 * coupleraient les tests à la mise en forme du message : celui-ci s'en passe.
 */
final class FakeAgent implements AgentInterface
{
    public ?MessageBag $lastMessages = null;

    /** @var array<string, mixed> */
    public array $lastOptions = [];

    public function __construct(
        private readonly ResultInterface|\Throwable $outcome,
    ) {
    }

    public function call(string|MessageBag|UserMessage $input, array $options = []): Execution
    {
        $this->lastMessages = $input instanceof MessageBag ? $input : new MessageBag(
            $input instanceof UserMessage ? $input : Message::ofUser($input),
        );
        $this->lastOptions = $options;

        if ($this->outcome instanceof \Throwable) {
            throw $this->outcome;
        }

        $result = $this->outcome;

        return new Execution(static function () use ($result): \Generator {
            yield new ResultUpdate($result);
        });
    }

    public function getName(): string
    {
        return 'fake';
    }

    /** Texte du dernier message utilisateur envoyé au modèle. */
    public function lastUserText(): string
    {
        $messages = $this->lastMessages ?? throw new \LogicException('Aucun appel enregistré.');

        foreach ($messages->getMessages() as $message) {
            if ($message instanceof UserMessage) {
                return $message->asText() ?? '';
            }
        }

        throw new \LogicException('Aucun message utilisateur dans le dernier appel.');
    }
}
