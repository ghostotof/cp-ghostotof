<?php

declare(strict_types=1);

namespace App\Security\Authentication\Infrastructure\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\Voter\AuthenticatedVoter;
use Symfony\Component\Security\Http\AccessMapInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * Une route que l'`access_control` laisse à l'anonyme est **servie comme à l'anonyme**, quel que
 * soit le cookie `BEARER` qu'elle reçoit (#748, R35, décision de l'auteur du 2026-10-29).
 * `JWTAuthenticator::supports()` (Lexik 3.2) répond `true` dès qu'un cookie est présent : un JWT
 * refusé — expiré, invalide, antérieur au changement de mot de passe — rendait son 401 même sur
 * une page publique. Sur `LoginFailureEvent`, la réponse devient `null` (« la requête continue »,
 * dit `AuthenticatorManager`) quand l'`AccessMap` n'exige rien. Une route qui exige un rôle garde
 * son 401 et l'effacement des deux cookies
 * ({@see \App\Security\Authentication\Infrastructure\Jwt\StaleSessionCookiesClearingListener}) ;
 * l'invariant n° 8 de `ApiRouteExposureTest` pince, sur chaque route, qu'un cookie refusé ne donne
 * jamais plus que l'anonyme.
 *
 * **Pourquoi l'`AccessMap`**, pas une liste des routes publiques : c'est la source de vérité de
 * l'`AccessListener`, aucune seconde liste n'est à tenir. La prémisse — toute autorisation de `/api`
 * est une règle d'`access_control`, et elle ne dépend que du chemin — est gardée par
 * `AuthorizationLivesInAccessControlTest` (les jetons), `ApiPlatformOperationsCarryNoSecurityTest`
 * (les métadonnées compilées d'API Platform) et `AccessMapStaysPathOnlyTest`.
 *
 * **Le dispatcher d'`api` seul**, le seul qui lit le cookie (`login` n'a pas de JWT, `mcp` et
 * `oauth` sont d'autres firewalls) ; **priorité -1024** pour avoir le dernier mot sur la réponse.
 * PublicRouteIgnoresRefusedSessionRegistrationTest le pince.
 *
 * **Une route publique ne touche pas au cookie refusé**, rien n'est posé ni effacé : la première
 * route protégée l'efface, et le SPA en lit une à chaque chargement (`GET /api/me`). Effacer aussi
 * sur une route publique étendrait la course CR3-2 (un 401 tardif qui efface des cookies neufs) à
 * chaque lecture publique. Une session antérieure au changement de mot de passe y est servie
 * comme l'anonyme et **auditée à chaque requête** (le signal « on rejoue une session close »).
 *
 * **Écartées** : `lazy: true` (le `supports()` du JWT reste `true`, et lire le jeton relancerait le
 * 401) ; un authentificateur dérivé de `JWTAuthenticator` (couplé à un constructeur du vendor, déjà
 * écarté par l'ADR 0003) ; un firewall sans authentificateur pour les routes publiques (une seconde
 * liste, qu'une route ajoutée oublierait en silence par un 401).
 */
#[AsEventListener(event: LoginFailureEvent::class, dispatcher: 'security.event_dispatcher.api', priority: -1024)]
final readonly class PublicRouteIgnoresRefusedSessionListener
{
    public function __construct(
        #[Autowire(service: 'security.access_map')]
        private AccessMapInterface $accessMap,
    ) {
    }

    public function __invoke(LoginFailureEvent $event): void
    {
        if (!$this->requiresAccess($event->getRequest())) {
            // `null` : la requête continue, anonyme. La réponse 401 de Lexik et
            // l'effacement des cookies qui y est attaché sont écartés.
            $event->setResponse(null);
        }
    }

    /**
     * La route exige-t-elle quelque chose de l'appelant ? Le prédicat d'`AccessListener::supports()` :
     * des attributs d'accès, et pas le seul `PUBLIC_ACCESS`.
     *
     * Publique parce que c'est le point de comparaison avec `AccessListener::supports()`, que lit
     * `PublicRouteIgnoresRefusedSessionTest` sur chaque route ; sans effet (St5-12).
     */
    public function requiresAccess(Request $request): bool
    {
        [$attributes] = $this->accessMap->getPatterns($request);

        return null !== $attributes && [] !== $attributes && [AuthenticatedVoter::PUBLIC_ACCESS] !== $attributes;
    }
}
