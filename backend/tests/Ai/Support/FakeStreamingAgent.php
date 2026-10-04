<?php

declare(strict_types=1);

namespace App\Tests\Ai\Support;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Execution\Execution;
use Symfony\AI\Agent\Execution\Update\Progress;
use Symfony\AI\Agent\Execution\Update\Result as ResultUpdate;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\TokenUsage\TokenUsage;

/**
 * Agent de test en flux. Comme le vrai Runner, il est paresseux : rien ne se
 * passe avant la première itération, et un échec « avant le premier fragment »
 * est levé à ce moment-là, pas par call(). Il diffuse des Progress('delta')
 * portant des TextDelta, puis le résultat final avec ses métadonnées.
 */
final class FakeStreamingAgent implements AgentInterface
{
    public ?MessageBag $lastMessages = null;

    /** @var array<string, mixed> */
    public array $lastOptions = [];

    /**
     * @param list<string> $fragments
     * @param int          $failAfter nombre de fragments diffusés avant $failure
     */
    public function __construct(
        private readonly array $fragments,
        private readonly ?TokenUsage $tokenUsage = null,
        private readonly ?\Throwable $failure = null,
        private readonly int $failAfter = 0,
    ) {
    }

    public function call(string|MessageBag|UserMessage $input, array $options = []): Execution
    {
        $this->lastMessages = $input instanceof MessageBag ? $input : new MessageBag(
            $input instanceof UserMessage ? $input : Message::ofUser($input),
        );
        $this->lastOptions = $options;

        return new Execution($this->run(...), true === ($options['stream'] ?? false));
    }

    public function getName(): string
    {
        return 'fake-streaming';
    }

    /**
     * @return \Generator<int, Progress|ResultUpdate, mixed, void>
     */
    private function run(): \Generator
    {
        foreach ($this->fragments as $index => $fragment) {
            if (null !== $this->failure && $index === $this->failAfter) {
                throw $this->failure;
            }

            yield new Progress('delta', 'Received a streamed delta.', new TextDelta($fragment));
        }

        if (null !== $this->failure && $this->failAfter >= \count($this->fragments)) {
            throw $this->failure;
        }

        $result = new TextResult(implode('', $this->fragments));
        if (null !== $this->tokenUsage) {
            $result->getMetadata()->add('token_usage', $this->tokenUsage);
        }

        yield new ResultUpdate($result);
    }
}
