<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Infrastructure\Http;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Shared\Infrastructure\Http\CanonicalPath;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Rend en problem+json typé les exceptions métier de l'assistant.
 *
 * POST /api/assistant/answers est un contrôleur, pas une opération API
 * Platform (la réponse est un flux) : ni `exception_to_status` ni
 * ProblemExceptionInterface ne s'y appliquent, et un fournisseur indisponible
 * sortait en 500 générique. Ce listener rend le même corps qu'API Platform —
 * `type` stable `/errors/<slug>` sur lequel le frontend s'appuie, statut, titre,
 * détail volontairement générique —, jamais la trace ni la cause.
 *
 * Limité au sous-arbre `/api/assistant` (chemin décodé, issue #77) : une route
 * API Platform garde son propre traitement. Priorité -64 : après la
 * journalisation de l'exception par Symfony (0), avant API Platform (-96) et
 * le rendu générique de Symfony (-128). Les 422 des bornes D6, le 413 du corps
 * trop volumineux et le 429 du quota passent par ici.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: -64)]
final readonly class AssistantProblemResponseListener
{
    private const string PATH_PREFIX = '/api/assistant';

    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof ProblemExceptionInterface || !$this->isAssistantPath(CanonicalPath::of($event->getRequest()))) {
            return;
        }

        $status = $exception->getStatus() ?? 500;

        $event->setResponse(new JsonResponse([
            'type' => $exception->getType(),
            'title' => $exception->getTitle(),
            'status' => $status,
            'detail' => $exception->getDetail(),
        ], $status, ['Content-Type' => 'application/problem+json']));
    }

    private function isAssistantPath(string $path): bool
    {
        return self::PATH_PREFIX === $path || str_starts_with($path, self::PATH_PREFIX.'/');
    }
}
