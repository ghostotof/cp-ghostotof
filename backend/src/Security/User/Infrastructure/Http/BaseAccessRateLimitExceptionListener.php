<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\Http;

use App\Security\User\Domain\Exception\BaseAccessRateLimitExceededException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

/**
 * POST /api/account/base-access n'est pas une ressource API Platform (simple
 * contrôleur, cf. BaseAccessController) : contrairement à
 * PasswordSetupRateLimitExceededException, il n'y a pas de exception_to_status
 * pour construire la réponse 429 à sa place. Ce listener en construit donc le
 * corps.
 *
 * L'en-tête Retry-After n'est pas posé ici mais par l'écouteur commun
 * App\Shared\Infrastructure\Http\RetryAfterListener (issue #273), qui a noté
 * l'échéance plus tôt dans `kernel.exception` (priorité 64, au-dessus de
 * celle-ci : setResponse() arrête la propagation).
 */
final class BaseAccessRateLimitExceptionListener
{
    #[AsEventListener(event: ExceptionEvent::class)]
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if (!$exception instanceof BaseAccessRateLimitExceededException) {
            return;
        }

        $event->setResponse(new JsonResponse(['detail' => $exception->getMessage()], 429));
    }
}
