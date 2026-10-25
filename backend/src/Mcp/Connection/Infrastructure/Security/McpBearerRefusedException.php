<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\Security;

use App\Mcp\Connection\Domain\ValueObject\BearerRefusal;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Un jeton présenté en `Authorization: Bearer` sur `/api/mcp` qui n'ouvre
 * rien (#648) : sa cause est nommée dans le message, et seulement là — le
 * client reçoit pour toutes la même réponse, un 401 `invalid_token`
 * (McpBearerAuthenticator::onAuthenticationFailure()).
 *
 * Le message est constant pour une cause : jamais le jeton, ni son empreinte,
 * ni un UUID (de la connexion, du compte). Le canal `security` le journalise
 * (« Authenticator failed. », `info`), seule trace de la cause.
 *
 * Levée dans `authenticate()` et rattrapée par l'`AuthenticatorManager` du
 * firewall, comme SubmittedPasswordTooLongException : elle n'atteint pas
 * `logKernelException`. Elle hérite pourtant le `#[WithHttpStatus(401)]`
 * d'AuthenticationException, d'où son entrée `log_level: info` dans
 * `framework.exceptions`, le niveau qu'elle aurait si elle s'échappait un jour.
 */
final class McpBearerRefusedException extends AuthenticationException
{
    public static function because(BearerRefusal $cause): self
    {
        return new self('MCP access token refused: '.$cause->value);
    }
}
