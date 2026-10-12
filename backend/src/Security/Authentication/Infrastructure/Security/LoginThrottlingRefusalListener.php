<?php

declare(strict_types=1);

namespace App\Security\Authentication\Infrastructure\Security;

use App\Security\Authentication\Domain\Exception\LoginRateLimitExceededException;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * Transforme le refus de `login_throttling` en 429 `/errors/rate-limited`
 * avec `Retry-After` (issue #399), au lieu du 401 que le failure handler de
 * Lexik a déjà construit pour lui.
 *
 * Il ne construit aucune réponse : il lève {@see LoginRateLimitExceededException},
 * que le chemin commun de tous les quotas rend (problem+json, `Retry-After`,
 * journalisation en `info`). Écrire ici une cinquième copie de ce rendu est
 * précisément ce que la règle « Every quota 429 » interdit.
 *
 * **Priorité -200**, la dernière de `LoginFailureEvent` : une exception levée
 * par un écouteur arrête la propagation, donc tout ce qui doit voir l'échec
 * passe avant — le compteur de `LoginThrottlingListener` (0), le journal
 * d'audit `login-throttled` de `SecurityEventsSubscriber` (0) et
 * {@see FailedLoginTimingEqualizer} (-100, qui écarte de toute façon ce cas).
 * LoginThrottlingRefusalListenerPriorityTest garde cette place sur le
 * dispatcher du firewall.
 *
 * **Jamais de `previous`** sur l'exception levée : l'ExceptionListener du
 * firewall (kernel.exception, priorité 1) parcourt toute la chaîne et reprend
 * la première AuthenticationException qu'il y trouve. Chaîner la cause lui
 * rendrait la main, et le refus redeviendrait un 401 sans `Retry-After`.
 *
 * **Firewall `login` seulement, et ce seul échec** : les autres 401 (identifiant
 * inconnu, mot de passe faux, compte en attente) restent ceux de Lexik, octet
 * pour octet — la moitié « contenu » de la non-énumération (audit A10).
 * Le firewall `api` ne connaît pas `login_throttling`.
 *
 * L'échéance vient du limiteur, par les minutes que Symfony en a tirées
 * (`ceil((échéance − maintenant) / 60)`, `LoginThrottlingListener`) : un
 * majorant d'au plus 59 s, sans relire le stockage du limiteur — le chemin
 * qu'un attaquant martèle reste aussi bon marché qu'avant. Faute de valeur
 * exploitable (absente ou nulle), une minute.
 */
#[AsEventListener(event: LoginFailureEvent::class, priority: -200)]
final readonly class LoginThrottlingRefusalListener
{
    /** Le firewall qui reçoit les identifiants (json_login), le seul où `login_throttling` s'applique. */
    private const string LOGIN_FIREWALL = 'login';

    /** Le délai retenu quand l'exception ne porte pas d'échéance exploitable. */
    private const int FALLBACK_MINUTES = 1;

    public function __construct(private ClockInterface $clock)
    {
    }

    public function __invoke(LoginFailureEvent $event): void
    {
        if (self::LOGIN_FIREWALL !== $event->getFirewallName()) {
            return;
        }

        $exception = $event->getException();

        if (!$exception instanceof TooManyLoginAttemptsAuthenticationException) {
            return;
        }

        throw new LoginRateLimitExceededException($this->clock->now()->modify(\sprintf('+%d minutes', $this->minutesUntilRetry($exception))));
    }

    private function minutesUntilRetry(TooManyLoginAttemptsAuthenticationException $exception): int
    {
        $minutes = $exception->getMessageData()['%minutes%'] ?? null;

        return \is_int($minutes) && $minutes > 0 ? $minutes : self::FALLBACK_MINUTES;
    }
}
