<?php

declare(strict_types=1);

namespace App\Security\Authentication\Infrastructure\Jwt;

use App\Security\Authentication\Infrastructure\Http\AuthCookieFactory;
use App\Security\Authentication\Infrastructure\Http\CsrfCookieTokenSigner;
use App\Security\User\Domain\Entity\CpgUser;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Complète la réponse de login_check générée par Lexik (qui pose déjà le
 * cookie httpOnly "BEARER" contenant le JWT, cf. config/packages/lexik_jwt_authentication.yaml) :
 *
 * - ajoute un second cookie XSRF-TOKEN, lisible en JS, dont la valeur est
 *   signée par APP_SECRET (cf. CsrfCookieTokenSigner, point d'audit B1) — le
 *   frontend le relit et le renvoie dans le header X-XSRF-TOKEN sur les
 *   requêtes qui changent l'état (double-submit cookie, cf.
 *   Authentication\Infrastructure\Http\CsrfCookieRequestSubscriber). Ses
 *   attributs viennent d'AuthCookieFactory (issue #87), jamais d'ici, et son
 *   échéance est celle du JWT (#748) ;
 * - remplace le corps JSON (vide une fois le token retiré) par les infos de
 *   l'utilisateur connecté, pour que le frontend n'ait pas à refaire un appel
 *   /api/me immédiatement après un login réussi.
 */
final readonly class LoginSuccessSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private CsrfCookieTokenSigner $csrfCookieTokenSigner,
        private AuthCookieFactory $authCookieFactory,
        // Durée du JWT : le XSRF-TOKEN meurt avec le BEARER que Lexik pose pour la même durée
        // (set_cookies.BEARER.lifetime, qui doit rester égale à ce paramètre).
        #[Autowire(param: 'lexik_jwt_authentication.token_ttl')]
        private int $sessionLifetimeSeconds,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            Events::AUTHENTICATION_SUCCESS => 'onAuthenticationSuccess',
        ];
    }

    public function onAuthenticationSuccess(AuthenticationSuccessEvent $event): void
    {
        /** @var CpgUser $user */
        $user = $event->getUser();

        $event->setData([
            'user' => [
                'username' => $user->getUserIdentifier(),
                'roles' => $user->getRoles(),
            ],
        ]);

        // Le XSRF-TOKEN expire avec le BEARER, comme à l'accès de base. Sans échéance, c'était
        // un cookie de session : un navigateur fermé puis rouvert sans restauration le jetait
        // alors que le BEARER, persistant, survivait ; chaque écriture partait sans jeton, prenait
        // un 403 que le frontend lit « session finie », et la boucle recommençait (#748, CR3).
        $event->getResponse()->headers->setCookie(
            $this->authCookieFactory->xsrf(
                $this->csrfCookieTokenSigner->issue(),
                time() + $this->sessionLifetimeSeconds,
            ),
        );
    }
}
