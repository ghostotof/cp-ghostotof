<?php

declare(strict_types=1);

namespace App\Security\Authentication\Infrastructure\Log;

use App\Contact\Domain\Exception\ContactRateLimitExceededException;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\User\Domain\Exception\BaseAccessRateLimitExceededException;
use App\Security\User\Domain\Exception\PasswordSetupRateLimitExceededException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

/**
 * Trace sur `security_audit` les quotas par IP des routes anonymes (issue
 * #356) : sans lui, leur 429 n'avait pour trace applicative que la ligne
 * générique du noyau, sans IP ni chemin.
 *
 * Un écouteur de kernel.exception plutôt qu'un appel aux trois sites qui
 * lèvent (deux écouteurs kernel.request, ContactMessageProcessor) : le
 * contexte Contact n'a pas à dépendre du journal de Security, et les
 * limiteurs restent ignorants de la journalisation. Le prix : ce tri connaît
 * l'exception de quota du domaine Contact, et il ne voit un refus que si
 * l'exception remonte jusqu'au noyau — c'est le cas des trois, rendus en 429
 * par API Platform (-96) ou ApiProblemResponseListener (-98).
 *
 * Priorité 0, comme le 403 backoffice de SecurityEventsSubscriber : au-dessus
 * de ces deux rendus, qui arrêtent la propagation. Seule la requête
 * principale compte. Le quota du traducteur, ROLE_SUPER, n'est pas trié ici :
 * il se trace sur `ai_usage` avec le compte.
 */
#[AsEventListener(event: ExceptionEvent::class, priority: 0)]
final readonly class ThrottledRequestAuditListener
{
    public function __construct(private SecurityAuditLoggerInterface $auditLogger)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $refusal = $event->getThrowable();

        match (true) {
            $refusal instanceof PasswordSetupRateLimitExceededException => $this->auditLogger->passwordSetupThrottled(),
            $refusal instanceof ContactRateLimitExceededException => $this->auditLogger->contactThrottled(),
            $refusal instanceof BaseAccessRateLimitExceededException => $this->auditLogger->baseAccessThrottled(),
            default => null,
        };
    }
}
