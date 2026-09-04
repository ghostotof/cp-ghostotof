<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\Http;

use App\Security\User\Application\PasswordSetupRateLimiterInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Borne le débit par IP du parcours public de définition de mot de passe
 * (GET/POST /api/account/password-setup/{token}) AVANT toute désérialisation ou
 * validation par API Platform.
 *
 * Point d'audit C1 (décision D1) : le comptage vivait auparavant dans
 * AccountPasswordSetupProvider / AccountPasswordSetupProcessor, c'est-à-dire
 * DANS le contrôleur API Platform, donc après la résolution du jeton et la
 * validation du corps. Conséquences corrigées ici :
 * - une requête au corps absent ou malformé n'était pas comptée alors qu'elle
 *   consomme quand même des ressources (deviner un jeton, sonder l'endpoint) ;
 * - une panne du service HIBP (cf. Assert\NotCompromisedPassword) renvoyait 500
 *   au lieu du 429 attendu quand le quota était déjà dépassé.
 *
 * Priorité 15 : après le routeur (priorité 32, les attributs `_api_*` sont donc
 * disponibles pour qu'API Platform convertisse l'exception en problem+json) et
 * après CsrfCookieRequestSubscriber (priorité 20), mais très en amont du
 * contrôleur. PasswordSetupRateLimitExceededException est mappée sur 429 via
 * exception_to_status (api_platform.yaml) ; PasswordSetupRateLimitRetryAfterListener
 * pose l'en-tête Retry-After sans modification.
 */
#[AsEventListener(event: RequestEvent::class, priority: 15)]
final readonly class PasswordSetupRateLimitRequestListener
{
    private const string PATH_PREFIX = '/api/account/password-setup/';

    /**
     * GET (consultation du statut du jeton) et POST (définition du mot de passe)
     * sont les deux seules méthodes exposées sur ce préfixe : toutes deux
     * doivent partager le même quota par IP.
     *
     * @var list<string>
     */
    private const array RATE_LIMITED_METHODS = ['GET', 'POST'];

    public function __construct(
        private PasswordSetupRateLimiterInterface $rateLimiter,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), self::PATH_PREFIX)) {
            return;
        }

        if (!\in_array($request->getMethod(), self::RATE_LIMITED_METHODS, true)) {
            return;
        }

        // getClientIp() dépend de framework.trusted_proxies pour être fiable
        // derrière l'ingress ; 'unknown' n'arrive qu'en l'absence totale d'IP
        // (CLI, tests), auquel cas tous ces appels partagent un seul compteur.
        $this->rateLimiter->consume($request->getClientIp() ?? 'unknown');
    }
}
