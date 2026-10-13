<?php

declare(strict_types=1);

namespace App\Security\Authentication\Infrastructure\Security;

use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

/**
 * Pourquoi un login a échoué, du point de vue de ce qui ne doit plus lire le
 * compte : {@see FailedLoginTimingEqualizer} et le journal d'audit
 * (`SecurityEventsSubscriber`).
 *
 * Les deux tenaient chacun leur liste des échecs survenus **sans compte
 * chargé** (issue #588) : un refus ajouté à l'une seulement aurait fait
 * appeler `Passport::getUser()`, qui charge le compte — une requête SQL pour
 * un identifiant existant seulement, donc un écart de temps (constat A10).
 * Le classement est écrit ici, une fois ; un nouveau refus anticipé y ajoute
 * son cas.
 */
enum LoginFailureCause
{
    /** `login_throttling` a refusé avant toute vérification. */
    case Throttled;

    /**
     * L'identifiant ne désigne aucun compte. `UserBadge` ne mémorise pas
     * l'échec : le rappeler relancerait la requête.
     */
    case UnknownIdentifier;

    /** Saisie au-delà de la borne du hasher, refusée avant tout chargement. */
    case OverlongPassword;

    /** Tout le reste : la vérification a eu lieu, sur un compte déjà chargé s'il y a un passeport. */
    case Other;

    public static function of(AuthenticationException $exception): self
    {
        return match (true) {
            $exception instanceof TooManyLoginAttemptsAuthenticationException => self::Throttled,
            // AuthenticatorManager::handleAuthenticationFailure() masque
            // l'UserNotFoundException derrière une BadCredentialsException dès
            // que `expose_security_errors` n'est pas `all`, en la gardant en
            // `previous` : les deux formes.
            $exception instanceof UserNotFoundException,
            $exception->getPrevious() instanceof UserNotFoundException => self::UnknownIdentifier,
            $exception instanceof SubmittedPasswordTooLongException => self::OverlongPassword,
            default => self::Other,
        };
    }

    /**
     * Vrai quand aucun compte n'est chargé dans le passeport : le lire
     * maintenant lancerait (ou relancerait) son chargement.
     */
    public function leftNoAccountLoaded(): bool
    {
        return self::Other !== $this;
    }
}
