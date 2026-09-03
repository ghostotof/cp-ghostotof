<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\Http;

use App\Security\User\Domain\Exception\PasswordSetupRateLimitExceededException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Pose l'en-tête HTTP standard `Retry-After` sur la réponse 429 déclenchée par
 * PasswordSetupRateLimitExceededException. Même mécanique en deux temps que
 * App\Contact\Infrastructure\Http\ContactRateLimitRetryAfterListener :
 * ExceptionEvent::setResponse() (API Platform) stoppe la propagation de
 * kernel.exception, on mémorise donc la date de réessai sur la requête puis on
 * lit cet attribut pendant kernel.response (toujours dispatché).
 */
final class PasswordSetupRateLimitRetryAfterListener
{
    private const string REQUEST_ATTRIBUTE = '_password_setup_rate_limit_retry_after';

    #[AsEventListener(event: KernelEvents::EXCEPTION)]
    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if (!$exception instanceof PasswordSetupRateLimitExceededException) {
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

        $retryAfterSeconds = max(0, $retryAfter->getTimestamp() - time());
        $event->getResponse()->headers->set('Retry-After', (string) $retryAfterSeconds);
    }
}
