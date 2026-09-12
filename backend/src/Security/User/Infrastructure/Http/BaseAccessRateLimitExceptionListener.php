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
 * pour construire la réponse 429 à sa place. Ce listener fait donc les deux
 * choses en un seul passage (corps + en-tête Retry-After), sans l'indirection
 * en deux temps utilisée côté API Platform.
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

        $retryAfterSeconds = max(0, $exception->retryAfter->getTimestamp() - time());

        $event->setResponse(new JsonResponse(
            ['detail' => $exception->getMessage()],
            429,
            ['Retry-After' => (string) $retryAfterSeconds],
        ));
    }
}
