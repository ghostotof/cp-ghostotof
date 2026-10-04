<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Infrastructure\SymfonyAi;

use App\Ai\Assistant\Application\CareerAssistantInterface;
use App\Ai\Assistant\Application\CareerAssistantSystemPrompt;
use App\Ai\Assistant\Domain\Exception\AssistantUnavailableException;
use App\Ai\Assistant\Domain\ValueObject\AnswerUsage;
use App\Ai\Assistant\Domain\ValueObject\Conversation;
use App\Ai\Assistant\Domain\ValueObject\Role;
use App\Ai\Shared\Infrastructure\SymfonyAi\ProviderFailure;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Execution\Execution;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Seule classe de l'assistant à importer Symfony\AI (ADR 0004 D1).
 *
 * L'agent est injecté par son id : `PlatformInterface` s'autowire sur la
 * plateforme Anthropic, et le corpus ne doit parvenir qu'au modèle opéré par
 * l'hébergeur du site (ADR 0004 D3, journal de la spec, tâche 1).
 *
 * Jamais de contenu dans un log (D10) : ni question, ni réponse, ni corpus,
 * ni message d'exception du fournisseur, que le bridge remplit avec le corps
 * de la réponse. Le statut HTTP et le type d'erreur de l'échec, eux, sont
 * journalisés, par ProviderFailure, partagée avec le traducteur (issue #308).
 *
 * Toute exception levée par l'appel ou par le flux est une panne du fournisseur :
 * 503 avant le premier fragment, événement `error` après. Pas seulement les
 * familles d'exceptions du bridge : une ligne SSE illisible (JsonException), une
 * ligne d'une forme inattendue (TypeError en construisant ses objets), ou ce que
 * vendor lève sans le ranger nulle part (ValueError, LogicException) ; sinon le
 * flux se coupe sans événement final ni fin sur `ai_usage` (#318). La classe et
 * le lieu de l'exception sont journalisés : un vrai bogue de ce côté-ci reste
 * reconnaissable.
 *
 * Journal sur le canal `ai_usage` (monolog.yaml), à niveau fixe en production :
 * sans cela, LOG_LEVEL=warning y écarterait l'usage de chaque réponse.
 */
#[WithMonologChannel('ai_usage')]
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
        } catch (\Throwable $exception) {
            throw $this->unavailable($exception, $conversation, 'before-first-fragment', $startedAt);
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
        } catch (\Throwable $exception) {
            throw $this->unavailable($exception, $conversation, 'during-stream', $startedAt);
        }

        $tokenUsage = $execution->getMetadata()->get('token_usage');
        $usage = new AnswerUsage(
            promptTokens: $tokenUsage instanceof TokenUsageInterface ? $tokenUsage->getPromptTokens() : null,
            completionTokens: $tokenUsage instanceof TokenUsageInterface ? $tokenUsage->getCompletionTokens() : null,
            durationMs: $this->elapsedMs($startedAt),
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

    /**
     * @param int $startedAt hrtime(true) au début de answer()
     */
    private function unavailable(\Throwable $exception, Conversation $conversation, string $stage, int $startedAt): AssistantUnavailableException
    {
        $this->logger->error('Assistant de parcours : le fournisseur a échoué.', [
            'outcome' => 'error',
            'stage' => $stage,
            // exception, providerStatus, providerErrorType et origin, sans
            // contenu (D10) : le lieu distingue un TypeError de câblage (ou un
            // ArgumentCountError, qui en hérite) d'une panne du bridge, qui
            // lève depuis vendor/.
            ...ProviderFailure::from($exception)->toLogContext(),
            // Les champs d'une fin réussie (D10) : une requête sur `ai_usage`
            // voit ainsi toutes les fins. Jetons inconnus, null explicite.
            'messageCount' => $conversation->count(),
            'durationMs' => $this->elapsedMs($startedAt),
            'promptTokens' => null,
            'completionTokens' => null,
        ]);

        return new AssistantUnavailableException();
    }

    private function elapsedMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
