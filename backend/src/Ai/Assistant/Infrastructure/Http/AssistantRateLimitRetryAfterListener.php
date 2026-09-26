<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Infrastructure\Http;

use App\Ai\Assistant\Domain\Exception\AssistantRateLimitExceededException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Ajoute Retry-After au 429 que rend AssistantProblemResponseListener pour
 * AssistantRateLimitExceededException — même mécanique que
 * TranslationRateLimitRetryAfterListener : l'échéance est notée sur la requête
 * quand l'exception est levée (priorité 0, avant le rendu à -64 qui arrête la
 * propagation), l'en-tête posé au moment de la réponse.
 */
final class AssistantRateLimitRetryAfterListener
{
    private const string REQUEST_ATTRIBUTE = '_assistant_rate_limit_retry_after';

    #[AsEventListener(event: KernelEvents::EXCEPTION)]
    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof AssistantRateLimitExceededException) {
            return;
        }

        $event->getRequest()->attributes->set(self::REQUEST_ATTRIBUTE, $exception->retryAfter);
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onKernelResponse(ResponseEvent $event): void
    {
        $retryAfter = $event->getRequest()->attributes->get(self::REQUEST_ATTRIBUTE);
        if (!$retryAfter instanceof \DateTimeImmutable) {
            return;
        }

        $event->getResponse()->headers->set('Retry-After', (string) max(0, $retryAfter->getTimestamp() - time()));
    }
}
