<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Domain\Exception\RetryAfterAware;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Pose l'en-tête HTTP standard `Retry-After` sur la réponse produite pour
 * toute exception RetryAfterAware (issue #273, qui a remplacé quatre copies
 * de cette mécanique, une par limiteur).
 *
 * **En deux temps.** Ce n'est pas lui qui construit le 429 : selon la route,
 * c'est l'`ExceptionListener` d'API Platform (`exception_to_status`, -96),
 * AssistantProblemResponseListener (-64) ou BaseAccessRateLimitExceptionListener
 * (0). Chacun appelle `ExceptionEvent::setResponse()`, qui arrête la
 * propagation de `kernel.exception`. L'échéance est donc notée sur la requête
 * pendant `kernel.exception`, puis lue pendant `kernel.response` — toujours
 * dispatché, quelle que soit la façon dont la réponse a été produite — pour
 * poser l'en-tête sur la réponse finale.
 *
 * **Priorité 64 sur `kernel.exception`**, au-dessus de tous ceux-là : noter
 * l'échéance après l'écouteur qui répond reviendrait à ne jamais la noter.
 * Cet écouteur n'appelle pas `setResponse()` et laisse donc passer
 * l'événement intact. Il n'est pas concerné par la priorité 16 de
 * RateLimiterLockFailureListener : une panne du verrou n'est pas
 * RetryAfterAware (son délai est fixe, posé par cet écouteur-là).
 *
 * Horloge injectée plutôt que `time()` : la valeur exacte se teste avec une
 * horloge figée.
 */
final readonly class RetryAfterListener
{
    private const string REQUEST_ATTRIBUTE = '_retry_after';

    public function __construct(private ClockInterface $clock)
    {
    }

    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: 64)]
    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof RetryAfterAware) {
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

        // Jamais négatif : l'échéance peut être dépassée le temps que la
        // réponse soit construite.
        $seconds = max(0, $retryAfter->getTimestamp() - $this->clock->now()->getTimestamp());
        $event->getResponse()->headers->set('Retry-After', (string) $seconds);
    }
}
