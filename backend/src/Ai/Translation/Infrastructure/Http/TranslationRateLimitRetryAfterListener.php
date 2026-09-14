<?php

declare(strict_types=1);

namespace App\Ai\Translation\Infrastructure\Http;

use App\Ai\Translation\Domain\Exception\TranslationRateLimitExceededException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Ajoute Retry-After au 429 produit par API Platform pour
 * TranslationRateLimitExceededException — même mécanique que
 * ContactRateLimitRetryAfterListener : l'exception est notée sur la requête
 * au moment où elle est levée, l'en-tête posé au moment de la réponse.
 */
final class TranslationRateLimitRetryAfterListener
{
    private const string REQUEST_ATTRIBUTE = '_translation_rate_limit_retry_after';

    #[AsEventListener(event: KernelEvents::EXCEPTION)]
    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof TranslationRateLimitExceededException) {
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
