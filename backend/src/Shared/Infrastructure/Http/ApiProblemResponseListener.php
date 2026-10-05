<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sous `/api`, rend en problem+json typé toute ProblemExceptionInterface levée
 * sur une route qui n'est pas une opération API Platform (issue #322).
 *
 * Un contrôleur (`POST /api/assistant/answers`, `POST /api/account/base-access`)
 * échappe à l'ExceptionListener d'API Platform, qui ne traite que les requêtes
 * portant `_api_respond` : ni `exception_to_status` ni ProblemExceptionInterface
 * ne s'y appliquent, et l'exception finissait en 500 générique. Chaque route
 * avait donc son propre écouteur de rendu. Celui-ci les remplace tous, avec le
 * corps qu'API Platform produirait : `type` stable `/errors/<slug>`, sur lequel
 * le frontend s'appuie, plus `title`, `status` et `detail`. Ni trace, ni cause :
 * le `detail` est un texte littéral (ProblemDetailStaysStaticTest).
 *
 * **Priorité -98, et c'est elle qui fait la règle.** API Platform (-96) arrête
 * la propagation sur toutes ses routes en posant sa réponse : ce qui arrive ici
 * est donc hors API Platform par construction, sans lire d'attribut interne
 * — sauf pendant `kernel.terminate`, où ce rendu s'abstient et où cet écouteur
 * s'abstient donc aussi.
 * Il doit aussi passer avant le rendu générique de Symfony (-128), sinon il
 * n'est jamais appelé. Il précède ApiJsonErrorFormatListener (-100), ce qui
 * est sans effet : celui-là ne fait que poser le format d'une erreur que
 * personne n'a rendue. ApiProblemResponseListenerPriorityTest lit le vrai
 * dispatcher et fige cet encadrement.
 *
 * Tout ce qui est plus haut passe avant, sans changement : RetryAfterListener
 * (64) note l'échéance d'un 429, RateLimiterLockFailureListener (16) rend son
 * 503, et ErrorListener::logKernelException (0) journalise l'exception au niveau
 * fixé par `framework.exceptions` — d'où l'entrée exigée pour chaque nouvelle
 * exception rendue ici, faute de quoi elle sort en `critical`.
 *
 * Écarté : `api_platform.handle_symfony_errors: true`, qui ferait rendre toutes
 * les erreurs par API Platform. Le réglage est global, sans filtre de chemin,
 * change le corps des 404/405/403 qui fonctionnent, et rend la main à Symfony
 * sur une négociation `html` avant ApiJsonErrorFormatListener.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: -98)]
final readonly class ApiProblemResponseListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        // Le forward vers le contrôleur d'erreur est une sous-requête : la
        // réponse se décide une seule fois, sur la requête principale.
        if (!$event->isMainRequest()) {
            return;
        }

        // Pendant `kernel.terminate`, la réponse est déjà partie. Le rendu que
        // délègue API Platform s'abstient alors (hors debug) sans poser de
        // réponse : la propagation arrive ici même depuis une route API
        // Platform, qui n'est pas la nôtre. Il n'y a rien à rendre.
        if ($event->isKernelTerminating()) {
            return;
        }

        $exception = $event->getThrowable();
        if (!$exception instanceof ProblemExceptionInterface || !CanonicalPath::isUnderApi($event->getRequest())) {
            return;
        }

        // Sans statut, l'exception ne dit rien d'une faute du client.
        $status = $exception->getStatus() ?? 500;

        $event->setResponse(new JsonResponse([
            'type' => $exception->getType(),
            'title' => $exception->getTitle(),
            'status' => $status,
            'detail' => $exception->getDetail(),
        ], $status, ['Content-Type' => 'application/problem+json']));
    }
}
