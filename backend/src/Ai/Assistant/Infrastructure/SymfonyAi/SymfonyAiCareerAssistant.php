<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Infrastructure\SymfonyAi;

use App\Ai\Assistant\Application\CareerAssistantInterface;
use App\Ai\Assistant\Application\CareerAssistantSystemPrompt;
use App\Ai\Assistant\Domain\Exception\AssistantUnavailableException;
use App\Ai\Assistant\Domain\ValueObject\AnswerUsage;
use App\Ai\Assistant\Domain\ValueObject\Conversation;
use App\Ai\Assistant\Domain\ValueObject\Role;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Exception\ExceptionInterface as AgentException;
use Symfony\AI\Agent\Execution\Execution;
use Symfony\AI\Platform\Exception\ExceptionInterface as PlatformException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

/**
 * Seule classe de l'assistant à importer Symfony\AI (ADR 0004 D1).
 *
 * L'agent est injecté par son id : `PlatformInterface` s'autowire sur la
 * plateforme Anthropic, et le corpus ne doit parvenir qu'au modèle opéré par
 * l'hébergeur du site (ADR 0004 D3, journal de la spec, tâche 1).
 *
 * Jamais de contenu dans un log (D10) : ni question, ni réponse, ni corpus,
 * ni message d'exception du fournisseur, que le bridge remplit avec le corps
 * de la réponse. Le statut HTTP de l'échec, lui, est journalisé.
 *
 * Une ligne SSE illisible (le bridge la décode avec JSON_THROW_ON_ERROR) est
 * une panne du fournisseur comme une autre : 503 ou événement `error`.
 */
final readonly class SymfonyAiCareerAssistant implements CareerAssistantInterface
{
    /**
     * Le bridge Scaleway fusionne ces options telles quelles dans le corps de
     * la requête (API compatible OpenAI). Sans `include_usage`, une telle API
     * ne transmet pas les jetons consommés quand elle diffuse.
     */
    private const array CALL_OPTIONS = [
        'stream' => true,
        'stream_options' => ['include_usage' => true],
    ];

    public function __construct(
        #[Autowire(service: 'ai.agent.career_assistant')]
        private AgentInterface $agent,
        private CareerAssistantSystemPrompt $systemPrompt,
        private LoggerInterface $logger,
    ) {
    }

    public function answer(Conversation $conversation, Locale $locale): \Generator
    {
        $startedAt = hrtime(true);
        $messages = $this->messageBag($conversation, $locale);

        try {
            $execution = $this->agent->call($messages, self::CALL_OPTIONS);
            $fragments = $this->textFragments($execution);
            // L'exécution est paresseuse : sans cet amorçage, la requête ne
            // partirait qu'une fois le statut 200 envoyé, et un fournisseur
            // injoignable ne pourrait plus devenir un 503.
            $fragments->current();
        } catch (PlatformException|AgentException|HttpClientException|\JsonException $exception) {
            throw $this->unavailable($exception, $conversation, 'before-first-fragment');
        }

        return $this->relay($fragments, $execution, $conversation, $startedAt);
    }

    private function messageBag(Conversation $conversation, Locale $locale): MessageBag
    {
        $messages = [Message::forSystem($this->systemPrompt->compose($locale))];
        foreach ($conversation->messages() as $message) {
            $messages[] = match ($message->role) {
                Role::User => Message::ofUser($message->content),
                Role::Assistant => Message::ofAssistant($message->content),
            };
        }

        return new MessageBag(...$messages);
    }

    /**
     * @return \Generator<int, string, mixed, void>
     */
    private function textFragments(Execution $execution): \Generator
    {
        foreach ($execution->asStream() as $delta) {
            if ($delta instanceof TextDelta && '' !== $delta->getText()) {
                yield $delta->getText();
            }
        }
    }

    /**
     * @param \Generator<int, string, mixed, void> $fragments déjà amorcé
     *
     * @return \Generator<int, string, mixed, AnswerUsage>
     */
    private function relay(\Generator $fragments, Execution $execution, Conversation $conversation, int $startedAt): \Generator
    {
        try {
            while ($fragments->valid()) {
                yield $fragments->current();
                $fragments->next();
            }
        } catch (PlatformException|AgentException|HttpClientException|\JsonException $exception) {
            throw $this->unavailable($exception, $conversation, 'during-stream');
        }

        $tokenUsage = $execution->getMetadata()->get('token_usage');
        $usage = new AnswerUsage(
            promptTokens: $tokenUsage instanceof TokenUsageInterface ? $tokenUsage->getPromptTokens() : null,
            completionTokens: $tokenUsage instanceof TokenUsageInterface ? $tokenUsage->getCompletionTokens() : null,
            durationMs: (int) round((hrtime(true) - $startedAt) / 1_000_000),
        );

        $this->logger->info('Assistant de parcours : réponse produite.', [
            'outcome' => 'done',
            'messageCount' => $conversation->count(),
            'durationMs' => $usage->durationMs,
            'promptTokens' => $usage->promptTokens,
            'completionTokens' => $usage->completionTokens,
        ]);

        return $usage;
    }

    private function unavailable(\Throwable $exception, Conversation $conversation, string $stage): AssistantUnavailableException
    {
        $this->logger->error('Assistant de parcours : le fournisseur a échoué.', [
            'outcome' => 'error',
            'stage' => $stage,
            'exception' => $exception::class,
            'providerStatus' => $this->providerStatus($exception),
            'messageCount' => $conversation->count(),
        ]);

        return new AssistantUnavailableException();
    }

    /**
     * Statut HTTP du fournisseur, sans jamais lire le corps : le bridge 0.13.0
     * réduit sinon tout échec à « unknown » (journal de la spec, tâche 1).
     */
    private function providerStatus(\Throwable $exception): ?int
    {
        if ($exception instanceof ServerException) {
            return $exception->getStatusCode();
        }

        if ($exception instanceof HttpExceptionInterface) {
            return $exception->getResponse()->getStatusCode();
        }

        // RuntimeException du bridge en flux : « Unexpected response code 403: "…" ».
        if (1 === preg_match('/^Unexpected response code (\d{3})\b/', $exception->getMessage(), $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
