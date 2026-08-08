<?php

declare(strict_types=1);

namespace App\Security\Authentication\Infrastructure\Jwt;

use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Complète la réponse de login_check générée par Lexik (qui pose déjà le
 * cookie httpOnly "BEARER" contenant le JWT, cf. config/packages/lexik_jwt_authentication.yaml) :
 *
 * - ajoute un second cookie XSRF-TOKEN, lisible en JS, valeur aléatoire — le
 *   frontend le relit et le renvoie dans le header X-XSRF-TOKEN sur les
 *   requêtes qui changent l'état (double-submit cookie, cf.
 *   Authentication\Infrastructure\Http\CsrfCookieRequestSubscriber) ;
 * - remplace le corps JSON (vide une fois le token retiré) par les infos de
 *   l'utilisateur connecté, pour que le frontend n'ait pas à refaire un appel
 *   /api/me immédiatement après un login réussi.
 */
final class LoginSuccessSubscriber implements EventSubscriberInterface
{
    public function __construct(#[Autowire('%kernel.environment%')] private readonly string $environment)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            Events::AUTHENTICATION_SUCCESS => 'onAuthenticationSuccess',
        ];
    }

    public function onAuthenticationSuccess(AuthenticationSuccessEvent $event): void
    {
        $event->setData([
            'user' => [
                'username' => $event->getUser()->getUserIdentifier(),
            ],
        ]);

        $event->getResponse()->headers->setCookie(
            Cookie::create('XSRF-TOKEN', bin2hex(random_bytes(32)))
                ->withHttpOnly(false)
                ->withSecure('prod' === $this->environment)
                ->withSameSite(Cookie::SAMESITE_LAX)
                ->withPath('/'),
        );
    }
}
