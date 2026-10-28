<?php

declare(strict_types=1);

namespace App\Security\Authentication\Infrastructure\Security;

use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Le JWT de session présenté a été émis à l'instant du dernier changement de
 * mot de passe du compte, ou avant (#650, R1b de la relecture) :
 * {@see SessionBeforePasswordChangeRefusalListener} le refuse.
 *
 * Une AuthenticationException de plus, rattrapée par l'`AuthenticatorManager` du
 * firewall `api` : l'authentificateur JWT de Lexik rend alors son 401 commun
 * (`{"code": 401, "message": …}`), celui que le frontend lit déjà comme une
 * session expirée — aucun corps nouveau, aucun `type` à apprendre. Une classe à
 * elle plutôt que `ExpiredTokenException` : la ligne `Authenticator failed.` du
 * canal `security` (`info`) en nomme la cause, ce qu'« Expired JWT Token » ne
 * dirait pas à qui lit un journal.
 *
 * Message constant : il ne cite ni le compte, ni le jeton, ni les deux instants
 * (le canal `security` journalise l'exception, #363). Il hérite pourtant le
 * `#[WithHttpStatus(401)]` d'AuthenticationException, d'où son entrée
 * `log_level: info` dans `framework.exceptions` (le niveau qu'elle aurait si elle
 * s'échappait un jour), comme SubmittedPasswordTooLongException.
 *
 * Dans `Infrastructure/`, pas dans `Domain/Exception` : elle étend une exception
 * du composant Security, dont le domaine ne dépend pas.
 */
final class SessionPredatesPasswordChangeException extends AuthenticationException
{
    /** Le texte du refus, tel que Lexik le met dans le corps du 401. */
    private const string MESSAGE_KEY = 'Session closed by a password change.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE_KEY);
    }

    public function getMessageKey(): string
    {
        return self::MESSAGE_KEY;
    }
}
