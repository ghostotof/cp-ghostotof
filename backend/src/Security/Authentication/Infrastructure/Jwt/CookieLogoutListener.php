<?php

declare(strict_types=1);

namespace App\Security\Authentication\Infrastructure\Jwt;

use App\Security\Authentication\Infrastructure\Http\AuthCookieFactory;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Le firewall "api" (config/packages/security.yaml) est stateless : "logout.path"
 * ne fait qu'intercepter la requête et déclencher LogoutEvent, sans réponse par
 * défaut. C'est ce listener qui construit la réponse et expire les deux cookies
 * posés au login (BEARER + XSRF-TOKEN, cf. LoginSuccessSubscriber) — sans lui,
 * le LogoutListener de Symfony lève une exception ("no response was set").
 *
 * Les cookies expirés viennent d'AuthCookieFactory (issue #87) : un cookie
 * « supprimé » dont Path, Secure ou SameSite diffèrent de la pose n'est pas
 * supprimé par le navigateur, et c'est ici que ce drift coûterait le plus —
 * une déconnexion qui ne déconnecte pas.
 */
final readonly class CookieLogoutListener implements EventSubscriberInterface
{
    public function __construct(private AuthCookieFactory $authCookieFactory)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LogoutEvent::class => 'onLogout',
        ];
    }

    public function onLogout(LogoutEvent $event): void
    {
        $response = new JsonResponse(null, Response::HTTP_NO_CONTENT);

        $response->headers->setCookie($this->authCookieFactory->expired(AuthCookieFactory::BEARER));
        $response->headers->setCookie($this->authCookieFactory->expired(AuthCookieFactory::XSRF_TOKEN));

        $event->setResponse($response);
    }
}
