<?php

declare(strict_types=1);

namespace App\Security\Authentication\Infrastructure\Jwt;

use App\Security\Authentication\Infrastructure\Http\AuthCookieFactory;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTExpiredEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTInvalidEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Le 401 d'un cookie `BEARER` refusé efface les deux cookies d'authentification,
 * comme la déconnexion (#650 R1b pour le mot de passe changé, #748 F1 pour tout
 * le reste).
 *
 * Le firewall `api` authentifie dès qu'un cookie `BEARER` est présent, **sur
 * une route publique comme sur les autres** : un JWT refusé y est un 401 pour
 * tout `/api`, le contenu public (À propos, la page de contact, le lien de
 * choix du mot de passe) compris. Tant que le cookie expirait avec le JWT, à la
 * même seconde, seul un cookie refusé pour un mot de passe changé posait le
 * problème. Depuis que `BEARER` et `XSRF-TOKEN` sont des cookies de session
 * (`set_cookies.BEARER.lifetime: 0`), **tout** cookie refusé — JWT expiré, JWT
 * invalide (signature, charge utile, compte disparu), session antérieure au
 * changement de mot de passe — resterait jusqu'à la fermeture du navigateur et
 * ferait de tout `/api` un 401. Effacé par son premier 401, la requête suivante
 * est anonyme, et servie.
 *
 * Le prix, accepté : ce premier 401 existe. Le frontend déconnecte l'accès de
 * base à son échéance (R21b) ; pour un login, dont l'échéance ne lui est pas
 * exposée, la première requête qui suit l'expiration du JWT le prend.
 *
 * Les cookies viennent d'AuthCookieFactory (issue #87, #714) : un cookie
 * « supprimé » aux attributs différents de la pose n'est pas supprimé.
 *
 * `JWT_NOT_FOUND` n'en est pas : sans cookie, rien à effacer.
 */
#[AsEventListener(event: Events::JWT_INVALID)]
#[AsEventListener(event: Events::JWT_EXPIRED)]
final readonly class StaleSessionCookiesClearingListener
{
    public function __construct(private AuthCookieFactory $authCookieFactory)
    {
    }

    public function __invoke(JWTInvalidEvent|JWTExpiredEvent $event): void
    {
        // La réponse du refus est celle que Lexik vient de construire : jamais
        // nulle ici, mais son type l'autorise.
        $response = $event->getResponse();
        if (null === $response) {
            return;
        }

        $response->headers->setCookie($this->authCookieFactory->expired(AuthCookieFactory::BEARER));
        $response->headers->setCookie($this->authCookieFactory->expired(AuthCookieFactory::XSRF_TOKEN));
    }
}
