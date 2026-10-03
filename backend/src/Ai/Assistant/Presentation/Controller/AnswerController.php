<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Presentation\Controller;

use App\Ai\Assistant\Application\CareerAssistantInterface;
use App\Ai\Assistant\Domain\Exception\AssistantUnavailableException;
use App\Ai\Assistant\Domain\ValueObject\AnswerUsage;
use App\Ai\Assistant\Domain\ValueObject\Conversation;
use App\Ai\Assistant\Presentation\Dto\AnswerRequest;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\EventStreamResponse;
use Symfony\Component\HttpFoundation\ServerEvent;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Assistant de parcours (spec 0005 D4, D9), réservé à ROLE_TRUSTED par
 * l'access_control `^/api/assistant(/|$)`, double-submit CSRF exigé comme sur
 * toute mutation /api. Un contrôleur plutôt qu'une ressource API Platform : la
 * réponse est un flux d'événements, qu'un processeur ne produit pas.
 *
 * Léger par construction : validation (MapRequestPayload), conversion en VO,
 * appel de l'interface, mise en forme des événements. L'assistant a déjà lancé
 * l'appel au fournisseur quand answer() rend la main : un échec avant le
 * premier fragment remonte d'ici en 503. Après le 200, il ne reste qu'à le dire
 * par un événement `error` : le flux se termine toujours par `done` ou `error`,
 * jamais par une simple coupure qu'un client prendrait pour une fin (#318).
 *
 * Chaque événement porte un `data` JSON (`delta` {text}, `done` {promptTokens,
 * completionTokens, durationMs}, `error` {reason}) : un fragment brut
 * contenant un saut de ligne serait découpé en plusieurs lignes `data:`.
 *
 * EventStreamResponse pose lui-même `X-Accel-Buffering: no` et un
 * Cache-Control `no-store`. L'en-tête coupe le tampon du nginx qui sert
 * l'application (le sidecar en production), qui le consomme : il ne va pas
 * plus loin, le client ne le reçoit pas (vérifié en dev le 2026-09-26). Au
 * delà, c'est le `proxy-buffering` de l'ingress-nginx, `off` par défaut et
 * non surchargé dans k8s/, qui laisse passer le flux.
 */
#[WithMonologChannel('ai_usage')]
final readonly class AnswerController
{
    public function __construct(
        private CareerAssistantInterface $assistant,
        private LoggerInterface $logger,
    ) {
    }

    // JSON seulement : un autre format (formulaire, XML) serait sinon
    // désérialisé et validé, une surface dont l'endpoint n'a aucun usage (415).
    #[Route('/api/assistant/answers', name: 'api_assistant_answers', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload(acceptFormat: 'json')] AnswerRequest $request): EventStreamResponse
    {
        // `from` et non `fromString` : la valeur est bornée par Assert\Choice,
        // elle ne vient pas d'une URL (spec §9).
        $conversation = $request->toConversation();
        $startedAt = hrtime(true);
        $fragments = $this->assistant->answer($conversation, Locale::from($request->locale));

        return new EventStreamResponse(function () use ($fragments, $conversation, $startedAt): \Generator {
            yield from $this->events($fragments, $conversation, $startedAt);
        });
    }

    /**
     * @param \Generator<int, string, mixed, AnswerUsage> $fragments
     * @param int                                         $startedAt hrtime(true) avant l'appel à l'assistant
     *
     * @return \Generator<int, ServerEvent, mixed, void>
     */
    private function events(\Generator $fragments, Conversation $conversation, int $startedAt): \Generator
    {
        try {
            foreach ($fragments as $fragment) {
                yield new ServerEvent($this->json(['text' => $fragment]), type: 'delta');
            }
        } catch (AssistantUnavailableException) {
            // Déjà journalisée sur `ai_usage` par l'assistant.
            yield $this->unavailableEvent();

            return;
        } catch (\Throwable $exception) {
            // Tout le reste : une exception du bridge que l'assistant ne relaie
            // pas, ou le json_encode d'un fragment ci-dessus. Les jetons sont
            // facturés, la fin doit donc exister sur `ai_usage` (D10) — la
            // classe seulement : le message d'une exception du bridge porte le
            // corps de la réponse du fournisseur.
            $this->logger->error('Assistant de parcours : le flux a échoué.', [
                'outcome' => 'error',
                'stage' => 'during-stream',
                'exception' => $exception::class,
                'origin' => basename($exception->getFile()).':'.$exception->getLine(),
                // Les champs de D10, comme sur les lignes de l'assistant. Les
                // jetons ne sont connus qu'en fin de flux : null explicite.
                'messageCount' => $conversation->count(),
                'durationMs' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
                'promptTokens' => null,
                'completionTokens' => null,
            ]);

            yield $this->unavailableEvent();

            return;
        }

        $usage = $fragments->getReturn();

        yield new ServerEvent($this->json([
            'promptTokens' => $usage->promptTokens,
            'completionTokens' => $usage->completionTokens,
            'durationMs' => $usage->durationMs,
        ]), type: 'done');
    }

    private function unavailableEvent(): ServerEvent
    {
        return new ServerEvent($this->json(['reason' => 'assistant-unavailable']), type: 'error');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data): string
    {
        return json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }
}
