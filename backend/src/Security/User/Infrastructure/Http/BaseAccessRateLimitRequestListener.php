<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\Http;

use App\Security\User\Application\BaseAccessRateLimiterInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Borne le débit par IP de POST /api/account/base-access (ADR 0003 D6), avant
 * le contrôleur. Même patron que PasswordSetupRateLimitRequestListener :
 * consommer le quota tôt évite qu'une panne ou une latence en aval ne
 * comptabilise différemment les tentatives.
 */
#[AsEventListener(event: RequestEvent::class, priority: 15)]
final readonly class BaseAccessRateLimitRequestListener
{
    private const string PATH = '/api/account/base-access';

    public function __construct(
        private BaseAccessRateLimiterInterface $rateLimiter,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (self::PATH !== $request->getPathInfo() || 'POST' !== $request->getMethod()) {
            return;
        }

        // getClientIp() dépend de framework.trusted_proxies pour être fiable
        // derrière l'ingress ; 'unknown' n'arrive qu'en l'absence totale d'IP
        // (CLI, tests), auquel cas tous ces appels partagent un seul compteur.
        $this->rateLimiter->consume($request->getClientIp() ?? 'unknown');
    }
}
