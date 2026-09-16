<?php

declare(strict_types=1);

namespace App\Security\Authentication\Infrastructure\Log;

use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Shared\Infrastructure\Http\CanonicalPath;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Traduit les événements de Symfony Security en appels au journal de
 * sécurité (D5). Il ne décide de rien, il trie :
 *
 *  - LoginSuccessEvent / LoginFailureEvent ne comptent que sur le firewall
 *    `login` (config/packages/security.yaml). Le firewall `api` ré-authentifie
 *    le JWT à CHAQUE requête et dispatche les mêmes événements : sans ce
 *    filtre, chaque appel authentifié serait un « login réussi » et chaque
 *    cookie expiré un « login raté » — le journal mentirait par le volume ;
 *  - un échec dû à login_throttling (TooManyLoginAttemptsAuthenticationException)
 *    a son propre événement : le mot de passe n'a pas été vérifié, ce n'est
 *    pas une mauvaise tentative de plus mais le déclenchement de la défense ;
 *  - le 403 backoffice se reconnaît sur kernel.exception à la priorité 0,
 *    c'est-à-dire APRÈS le Firewall\ExceptionListener (priorité 1) qui
 *    enveloppe l'AccessDeniedException du voter dans une
 *    AccessDeniedHttpException — et AVANT API Platform (-96) qui fixe la
 *    réponse et arrête la propagation. On exige l'enveloppe complète
 *    (`previous` = AccessDeniedException) : les deux gardes CSRF lèvent la
 *    même classe HTTP à nu, et leur rejet est déjà journalisé sous son propre
 *    nom. Un anonyme, lui, est renvoyé au point d'entrée (401) par ce même
 *    listener, qui arrête alors la propagation : ce cas ne passe jamais ici.
 */
final readonly class SecurityEventsSubscriber implements EventSubscriberInterface
{
    /** Le firewall qui reçoit les identifiants (json_login), le seul où un login a lieu. */
    private const string LOGIN_FIREWALL = 'login';

    /** Même ancre que la règle d'access_control (issue #78) : le sous-arbre, pas un voisin. */
    private const string BACKOFFICE_PATH_PATTERN = '#^/api/backoffice(/|$)#';

    public function __construct(private SecurityAuditLoggerInterface $auditLogger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
            LoginFailureEvent::class => 'onLoginFailure',
            LogoutEvent::class => 'onLogout',
            KernelEvents::EXCEPTION => ['onKernelException', 0],
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        if (self::LOGIN_FIREWALL !== $event->getFirewallName()) {
            return;
        }

        $this->auditLogger->loginSucceeded($event->getUser()->getUserIdentifier());
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        if (self::LOGIN_FIREWALL !== $event->getFirewallName()) {
            return;
        }

        $username = $event->getPassport()?->getBadge(UserBadge::class)?->getUserIdentifier();

        if ($event->getException() instanceof TooManyLoginAttemptsAuthenticationException) {
            $this->auditLogger->loginThrottled($username);

            return;
        }

        $this->auditLogger->loginFailed($username);
    }

    public function onLogout(LogoutEvent $event): void
    {
        $this->auditLogger->loggedOut($event->getToken()?->getUserIdentifier());
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $throwable = $event->getThrowable();

        if (!$throwable instanceof AccessDeniedHttpException || !$throwable->getPrevious() instanceof AccessDeniedException) {
            return;
        }

        // Décodé (issue #77) : `/api/%62ackoffice` est bien le backoffice pour le firewall.
        if (1 !== preg_match(self::BACKOFFICE_PATH_PATTERN, CanonicalPath::of($event->getRequest()))) {
            return;
        }

        $this->auditLogger->backofficeAccessDenied();
    }
}
