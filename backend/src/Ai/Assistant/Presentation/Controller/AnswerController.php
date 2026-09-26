<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Presentation\Controller;

use App\Ai\Assistant\Application\CareerAssistantInterface;
use App\Ai\Assistant\Domain\Exception\AssistantUnavailableException;
use App\Ai\Assistant\Domain\ValueObject\AnswerUsage;
use App\Ai\Assistant\Presentation\Dto\AnswerRequest;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
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
 * par un événement `error`.
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
final readonly class AnswerController
{
    public function __construct(
        private CareerAssistantInterface $assistant,
    ) {
    }

    #[Route('/api/assistant/answers', name: 'api_assistant_answers', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] AnswerRequest $request): EventStreamResponse
    {
        // `from` et non `fromString` : la valeur est bornée par Assert\Choice,
        // elle ne vient pas d'une URL (spec §9).
        $fragments = $this->assistant->answer($request->toConversation(), Locale::from($request->locale));

        return new EventStreamResponse(function () use ($fragments): \Generator {
            yield from $this->events($fragments);
        });
    }

    /**
     * @param \Generator<int, string, mixed, AnswerUsage> $fragments
     *
     * @return \Generator<int, ServerEvent, mixed, void>
     */
    private function events(\Generator $fragments): \Generator
    {
        try {
            foreach ($fragments as $fragment) {
                yield new ServerEvent($this->json(['text' => $fragment]), type: 'delta');
            }
        } catch (AssistantUnavailableException) {
            yield new ServerEvent($this->json(['reason' => 'assistant-unavailable']), type: 'error');

            return;
        }

        $usage = $fragments->getReturn();

        yield new ServerEvent($this->json([
            'promptTokens' => $usage->promptTokens,
            'completionTokens' => $usage->completionTokens,
            'durationMs' => $usage->durationMs,
        ]), type: 'done');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data): string
    {
        return json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }
}
