<?php

declare(strict_types=1);

namespace App\Security\Authentication\Infrastructure\Log;

use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Shared\Infrastructure\Http\CanonicalPath;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Throwable;

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
 *  - un échec transmet l'identifiant tenté ET le compte déjà chargé, s'il y
 *    en a un : le journal n'écrit que l'empreinte du premier, et ne désigne
 *    que le second, par son UUID (issue #418, un mot de passe tapé comme
 *    identifiant ; issue #363, un identifiant dérivé d'une adresse) ;
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

    /** Même ancre que la règle d'access_control (issue #78, CanonicalPath::isUnder) : le sous-arbre, pas un voisin. */
    private const string BACKOFFICE_PATH = '/api/backoffice';

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

        $this->auditLogger->loginSucceeded($event->getUser());
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        if (self::LOGIN_FIREWALL !== $event->getFirewallName()) {
            return;
        }

        $triedIdentifier = $event->getPassport()?->getBadge(UserBadge::class)?->getUserIdentifier();

        if ($event->getException() instanceof TooManyLoginAttemptsAuthenticationException) {
            // Refusé avant le chargement du compte : le charger ici coûterait
            // une requête SQL sur le chemin même que l'attaquant martèle.
            $this->auditLogger->loginThrottled($triedIdentifier);

            return;
        }

        $this->auditLogger->loginFailed($triedIdentifier, $this->loadedAccount($event));
    }

    /**
     * Le compte que l'identifiant tenté désigne, s'il a déjà été chargé par
     * l'authentification — jamais au prix d'une requête de plus (issue #418).
     * Même raisonnement que
     * {@see \App\Security\Authentication\Infrastructure\Security\FailedLoginTimingEqualizer} :
     * un identifiant inconnu se reconnaît à l'exception, nue ou masquée par
     * {@see \Symfony\Component\Security\Http\Authentication\AuthenticatorManager}
     * derrière une
     * {@see \Symfony\Component\Security\Core\Exception\BadCredentialsException}
     * qui la garde en `previous` —
     * UserBadge ne mémorise pas l'échec, le rappeler relancerait la requête.
     * Dans les autres cas qui arrivent ici,
     * {@see \Symfony\Component\Security\Http\EventListener\CheckCredentialsListener}
     * a déjà chargé le compte et UserBadge le mémorise. Une panne de la base
     * n'arrive jamais ici : l'exception DBAL n'est pas une
     * {@see \Symfony\Component\Security\Core\Exception\AuthenticationException},
     * le gestionnaire d'authentification ne la rattrape pas et la requête
     * finit en 500, sans LoginFailureEvent.
     */
    private function loadedAccount(LoginFailureEvent $event): ?CpgUser
    {
        $exception = $event->getException();

        if ($exception instanceof UserNotFoundException || $exception->getPrevious() instanceof UserNotFoundException) {
            return null;
        }

        $passport = $event->getPassport();

        // Absent quand l'échec précède le passeport (JSON invalide, champ vide).
        if (null === $passport || !$passport->hasBadge(UserBadge::class)) {
            return null;
        }

        try {
            $user = $passport->getUser();
        } catch (Throwable) {
            // Chargeur en échec (fournisseur qui ne rend pas un utilisateur,
            // par exemple) : le 401 est déjà construit, la ligne sort quand
            // même — sans compte, avec l'empreinte.
            return null;
        }

        return $user instanceof CpgUser ? $user : null;
    }

    public function onLogout(LogoutEvent $event): void
    {
        $this->auditLogger->loggedOut($event->getToken()?->getUser());
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
        if (!CanonicalPath::isUnder($event->getRequest(), self::BACKOFFICE_PATH)) {
            return;
        }

        $this->auditLogger->backofficeAccessDenied();
    }
}
