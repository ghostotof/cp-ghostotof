<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\Http;

use App\Security\User\Application\PasswordSetupRateLimiterInterface;
use App\Shared\Infrastructure\Http\CanonicalPath;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Borne le débit par IP du parcours public de définition de mot de passe
 * (POST /api/account/password-setup et POST …/password-setup/validate) AVANT
 * toute désérialisation ou validation par API Platform : un corps invalide
 * (422) consomme le quota comme un autre.
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
    /**
     * Les deux routes du parcours, et elles seules (audit A7, D6 : le jeton
     * est dans le corps, il n'y a plus de segment variable). Comparaison
     * EXACTE plutôt que par préfixe, pour rester aligné sur le routeur :
     * - un préfixe `/api/account/password-setup` engloberait par accident une
     *   route sœur (`…/password-setup-autre`) ;
     * - un préfixe `…/password-setup/` manquerait la route racine ;
     * - le routeur ne sert ni variante à barre oblique finale (pas de
     *   redirection pour une route POST) ni suffixe `.{_format}` : aucun autre
     *   chemin n'atteint ces deux opérations
     *   (AccountPasswordSetupResourceTest::testTrailingSlashVariantsAreNotServed).
     * Ajouter une route au parcours = l'ajouter ici, sinon elle n'a pas de quota.
     *
     * @var list<string>
     */
    private const array RATE_LIMITED_PATHS = [
        '/api/account/password-setup',
        '/api/account/password-setup/validate',
    ];

    /**
     * POST seul : c'est l'unique méthode exposée sur ces deux chemins, toute
     * autre est un 405 du routeur qui ne touche ni jeton ni base.
     */
    private const string RATE_LIMITED_METHOD = 'POST';

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

        // Chemin décodé (CanonicalPath, issue #77) : `password%2Dsetup` est
        // routé vers la ressource, il doit consommer le même quota.
        if (!\in_array(CanonicalPath::of($request), self::RATE_LIMITED_PATHS, true)) {
            return;
        }

        if (self::RATE_LIMITED_METHOD !== $request->getMethod()) {
            return;
        }

        // getClientIp() dépend de framework.trusted_proxies pour être fiable
        // derrière l'ingress ; 'unknown' n'arrive qu'en l'absence totale d'IP
        // (CLI, tests), auquel cas tous ces appels partagent un seul compteur.
        $this->rateLimiter->consume($request->getClientIp() ?? 'unknown');
    }
}
