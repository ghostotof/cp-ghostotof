<?php

declare(strict_types=1);

namespace App\Security\Authentication\Infrastructure\Jwt;

use App\Security\Authentication\Infrastructure\Http\AuthCookieFactory;
use App\Security\Authentication\Infrastructure\Security\SessionPredatesPasswordChangeException;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTInvalidEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Le 401 d'une session antérieure au changement de mot de passe (#650, R1b)
 * efface les deux cookies d'authentification, comme la déconnexion.
 *
 * Le firewall `api` authentifie dès qu'un cookie `BEARER` est présent, **sur
 * une route publique comme sur les autres** : un JWT refusé y est un 401 pour
 * tout `/api`, le contenu public (À propos, la page de contact, le lien de
 * choix du mot de passe) compris. Un jeton expiré ne pose pas le problème — le
 * cookie expire avec lui, à la même seconde —, mais un cookie refusé pour un
 * mot de passe changé a encore jusqu'à une heure à vivre : sans cet effacement,
 * le navigateur de la personne dont le mot de passe vient de changer renverrait
 * 401 à tout le site jusque-là. Effacé, la requête suivante est anonyme, et le
 * frontend, qui lit déjà ce 401 comme une session expirée, la renvoie à la
 * connexion.
 *
 * Les cookies viennent d'AuthCookieFactory (issue #87, #714) : un cookie
 * « supprimé » aux attributs différents de la pose n'est pas supprimé.
 *
 * **Ce refus seulement** : les autres JWT refusés (invalide, expiré) gardent leur
 * réponse d'avant, octet pour octet — hors du périmètre de R1b.
 */
#[AsEventListener(event: Events::JWT_INVALID)]
final readonly class StaleSessionCookiesClearingListener
{
    public function __construct(private AuthCookieFactory $authCookieFactory)
    {
    }

    public function __invoke(JWTInvalidEvent $event): void
    {
        if (!$event->getException() instanceof SessionPredatesPasswordChangeException) {
            return;
        }

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
