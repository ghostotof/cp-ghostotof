<?php

declare(strict_types=1);

namespace App\Contact\Infrastructure\Http;

use App\Contact\Domain\Exception\ContactRateLimitExceededException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Ajoute l'en-tête HTTP standard `Retry-After` sur la réponse 429 déclenchée
 * par ContactRateLimitExceededException (cf. exception_to_status dans
 * api_platform.yaml).
 *
 * En deux temps car ExceptionEvent::setResponse() (appelé par le
 * ExceptionListener d'API Platform) stoppe immédiatement la propagation de
 * l'événement kernel.exception — un listener kernel.exception à priorité
 * plus basse ne serait donc jamais invoqué. On stocke la date de
 * réessai sur la requête pendant kernel.exception, puis on lit cet
 * attribut pendant kernel.response (toujours dispatché, quelle que soit la
 * façon dont la réponse a été produite) pour poser l'en-tête sur la réponse
 * finale déjà construite.
 */
final class ContactRateLimitRetryAfterListener
{
    private const string REQUEST_ATTRIBUTE = '_contact_rate_limit_retry_after';

    #[AsEventListener(event: KernelEvents::EXCEPTION)]
    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if (!$exception instanceof ContactRateLimitExceededException) {
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
