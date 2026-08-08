<?php

declare(strict_types=1);

namespace App\Security\Authentication\Infrastructure\Jwt;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Le firewall "api" (config/packages/security.yaml) est stateless : "logout.path"
 * ne fait qu'intercepter la requête et déclencher LogoutEvent, sans réponse par
 * défaut. C'est ce listener qui construit la réponse et expire les deux cookies
 * posés au login (BEARER + XSRF-TOKEN, cf. LoginSuccessSubscriber) — sans lui,
 * le LogoutListener de Symfony lève une exception ("no response was set").
 */
final class CookieLogoutListener implements EventSubscriberInterface
{
    public function __construct(#[Autowire('%kernel.environment%')] private readonly string $environment)
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

        $secure = 'prod' === $this->environment;
        $response->headers->clearCookie('BEARER', '/', null, $secure, true, Cookie::SAMESITE_LAX);
        $response->headers->clearCookie('XSRF-TOKEN', '/', null, $secure, false, Cookie::SAMESITE_LAX);

        $event->setResponse($response);
    }
}
