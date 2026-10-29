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
 * Une route que l'`access_control` laisse à l'anonyme est **servie comme à
 * l'anonyme**, quel que soit le cookie `BEARER` qu'elle reçoit (#748, C1 côté
 * serveur, décision de l'auteur du 2026-10-29).
 *
 * **Le défaut** : `JWTAuthenticator::supports()` (Lexik 3.2) répond `true` dès
 * qu'un cookie `BEARER` est présent, quelle que soit la route. Un JWT refusé —
 * expiré, invalide, antérieur au changement de mot de passe — passe alors par
 * `onAuthenticationFailure()`, dont la réponse 401 est rendue telle quelle par
 * `AuthenticatorManager::handleAuthenticationFailure()` : le contenu public (À
 * propos, la page de contact, le lien de choix du mot de passe, « Accès
 * instantané ») répondait 401 à un navigateur qui gardait un cookie mort. Depuis
 * que `BEARER` est un cookie de session, ce cookie reste jusqu'à la fermeture du
 * navigateur. Le SPA s'en protégeait en n'envoyant pas ses cookies aux routes
 * publiques (`credentials: 'omit'`), ce qui retire aussi l'authentification HTTP
 * de la préprod : la cause est corrigée ici.
 *
 * **Le correctif** : sur `LoginFailureEvent`, la réponse est remplacée par `null`
 * (« returning null is ok, it means they want the request to continue », dit
 * `AuthenticatorManager`) quand l'`AccessMap` n'exige rien pour la requête. La
 * requête poursuit anonyme ; l'`AccessListener` ne demande rien non plus, et la
 * route répond ce qu'elle répond à tout le monde. Une route qui exige un rôle
 * garde exactement son 401 et l'effacement des deux cookies
 * ({@see \App\Security\Authentication\Infrastructure\Jwt\StaleSessionCookiesClearingListener}).
 * Un `BEARER` refusé ne donne jamais plus que l'anonyme : l'invariant n° 8 de
 * `ApiRouteExposureTest` le pince sur chaque route et chaque méthode.
 *
 * **Pourquoi l'`AccessMap`**, et pas une liste des routes publiques : c'est la
 * source de vérité que lit l'`AccessListener` — toute autorisation de `/api`
 * passe par `access_control` (ni `security:` d'API Platform, ni `#[IsGranted]`,
 * ni `denyAccessUnlessGranted` dans `src/`), et `AccessMapStaysPathOnlyTest`
 * garantit que la règle ne dépend que du chemin. Une route publique est donc une
 * route **sans règle** ; aucune seconde liste n'est à tenir à la main à côté de
 * `access_control` et de `PUBLIC_PATHS`. Le prédicat {@see self::requiresAccess()}
 * est celui d'`AccessListener::supports()` (`$attributes` non vide et différent
 * de `[PUBLIC_ACCESS]`), recopié en une méthode : un test compare les deux verdicts
 * sur chaque route du routeur.
 *
 * **Inscrit sur le seul dispatcher du firewall `api`** (`dispatcher:`), le seul
 * qui lit le cookie : `login` n'a pas de JWT, `mcp` et `oauth` sont d'autres
 * firewalls. **Dernière priorité (-1024)** : l'écouteur a le dernier mot sur la
 * réponse, après tout autre écouteur qui voudrait la poser.
 * PublicRouteIgnoresRefusedSessionRegistrationTest le pince.
 *
 * **Une route publique ne touche pas au cookie refusé** : la réponse de Lexik, avec
 * l'effacement que `StaleSessionCookiesClearingListener` y ajoute, est écartée.
 * Le cookie mort tombe à la première route protégée qu'il atteint, et le SPA en
 * appelle une à chaque chargement (`GET /api/me`). Avantage retenu : la course
 * CR3-2 (un 401 tardif qui efface des cookies neufs) reste limitée aux requêtes
 * authentifiées ; effacer aussi sur une route publique l'étendrait à chaque lecture
 * publique, les plus nombreuses. Lexik construit tout de même sa réponse et émet
 * `JWT_INVALID` / `JWT_EXPIRED` pour rien : coût négligeable.
 *
 * **Une session antérieure au changement de mot de passe** présentée à une route
 * publique est servie comme l'anonyme et **auditée à chaque requête**
 * (SessionBeforePasswordChangeRefusalListener audite avant de lever, le chemin
 * public dans la ligne) : rien de plus que l'anonyme n'est servi, et la ligne garde
 * le signal « quelqu'un rejoue une session close ».
 *
 * **Écartées** : `lazy: true` sur `api` (ne change rien : l'authentificateur JWT
 * répond `true` à `supports()`, le contexte reste immédiat ; et toute lecture du
 * jeton relancerait le 401 par `LazyResponseException`) ; un authentificateur dérivé
 * de `JWTAuthenticator` (hérite d'une classe du vendor, couplé à son constructeur —
 * l'ADR 0003 a déjà écarté « un authentificateur Lexik décoré ») ; un firewall sans
 * authentificateur pour les routes publiques (une seconde liste de ce qui est
 * public, qu'une route ajoutée oublierait en silence par un 401).
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
     */
    public function requiresAccess(Request $request): bool
    {
        [$attributes] = $this->accessMap->getPatterns($request);

        return null !== $attributes && [] !== $attributes && [AuthenticatedVoter::PUBLIC_ACCESS] !== $attributes;
    }
}
