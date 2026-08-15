<?php

declare(strict_types=1);

namespace App\Security\Authentication\Infrastructure\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Protection CSRF en double-submit cookie pour l'authentification par cookie
 * httpOnly (le SameSite=Lax du cookie BEARER ne suffit pas à lui seul contre
 * toutes les formes de CSRF). Pour toute méthode qui change l'état sous /api
 * (hors chemins de EXCLUDED_PATHS), le header X-XSRF-TOKEN doit être présent
 * et correspondre au cookie XSRF-TOKEN posé au login (Jwt\LoginSuccessSubscriber) :
 * un attaquant cross-site peut faire envoyer le cookie automatiquement par le
 * navigateur, mais ne peut pas le lire pour le recopier dans un header
 * (same-origin policy).
 *
 * Priorité 20, volontairement au-dessus du firewall Security (priorité 8) :
 * pour /api/logout, le LogoutListener de Symfony fixe la réponse (donc stoppe
 * la propagation de l'événement) dès son passage. Une priorité inférieure à 8
 * ne verrait donc jamais passer cette requête.
 */
#[AsEventListener(event: RequestEvent::class, priority: 20)]
final class CsrfCookieRequestSubscriber
{
    private const array UNSAFE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * - /api/login_check : n'a pas encore de cookie XSRF-TOKEN (posé au login,
     *   donc après cette requête).
     * - /api/contact : endpoint public, jamais atteint avec une session ou un
     *   cookie BEARER à protéger (un visiteur anonyme n'a pas d'"état ambiant"
     *   qu'un attaquant pourrait faire agir à son insu) — la protection
     *   double-submit-cookie n'a pas de sens ici. Le spam reste un risque
     *   distinct, traité par le honeypot de ContactMessageResource.
     */
    private const array EXCLUDED_PATHS = ['/api/login_check', '/api/contact'];
    private const string COOKIE_NAME = 'XSRF-TOKEN';
    private const string HEADER_NAME = 'X-XSRF-TOKEN';

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!$this->requiresCsrfCheck($request)) {
            return;
        }

        $cookieToken = $request->cookies->get(self::COOKIE_NAME);
        $headerToken = $request->headers->get(self::HEADER_NAME);

        // hash_equals('', '') vaut `true` : un cookie/header vidé (plutôt
        // qu'absent, ex. cookie effacé côté client) ne doit pas être traité
        // comme une correspondance valide, d'où le rejet explicite des
        // chaînes vides en plus du cas `null`.
        if (!\is_string($cookieToken) || !\is_string($headerToken) || '' === $cookieToken || '' === $headerToken || !hash_equals($cookieToken, $headerToken)) {
            throw new AccessDeniedHttpException('En-tête CSRF manquant ou invalide.');
        }
    }

    private function requiresCsrfCheck(Request $request): bool
    {
        if (!\in_array($request->getMethod(), self::UNSAFE_METHODS, true)) {
            return false;
        }

        if (!str_starts_with($request->getPathInfo(), '/api')) {
            return false;
        }

        return !\in_array($request->getPathInfo(), self::EXCLUDED_PATHS, true);
    }
}
