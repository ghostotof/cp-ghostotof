<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use App\Mcp\Connection\Domain\ValueObject\GrantRefusal;
use League\OAuth2\Server\Exception\OAuthServerException;

/**
 * Le classement d'un refus de league en GrantRefusal (#725), écrit ici seul.
 *
 * league ne nomme ses refus que par leur type d'erreur (RFC 6749 §5.2) et
 * leur indication, une phrase anglaise : c'est elle qui distingue un
 * vérificateur faux d'une redirection autre, tous deux sous `invalid_grant`
 * ou `invalid_request`. Les indications sont celles de league **9.4.1**,
 * épinglée exacte (`.claude/rules/mcp-oauth.md`) ; une montée de version qui
 * en change une fait tomber son refus dans GrantRefusal::Unrecognised, et
 * `GrantRefusalAuditTest`, qui produit chaque cause par le vrai league,
 * rougit.
 */
final class LeagueGrantRefusals
{
    /** L'indication de chaque refus, telle que league (ou ce dépôt) l'écrit. */
    private const array CAUSE_OF_HINT = [
        'Check the `code_verifier` parameter' => GrantRefusal::VerifierMissing,
        'Code Verifier must follow the specifications of RFC-7636.' => GrantRefusal::VerifierMalformed,
        'Failed to verify `code_verifier`.' => GrantRefusal::VerifierMismatch,
        'Authorization code has expired' => GrantRefusal::CodeExpired,
        'Authorization code has been revoked' => GrantRefusal::CodeRefused,
        'Authorization code malformed' => GrantRefusal::NotACode,
        'Authorization code was not issued to this client' => GrantRefusal::ForeignClient,
        'Check the `redirect_uri` parameter' => GrantRefusal::RedirectMismatch,
        'Invalid redirect URI' => GrantRefusal::RedirectMismatch,
        'Token has expired' => GrantRefusal::TokenExpired,
        'Token has been revoked' => GrantRefusal::TokenRefused,
        'Token is not linked to client' => GrantRefusal::ForeignClient,
        // PayloadCheckedRefreshTokenGrant : un code présenté comme rafraîchissement.
        'Check the `refresh_token` parameter: it is not a refresh token.' => GrantRefusal::NotARefreshToken,
    ];

    private function __construct()
    {
    }

    public static function causeOf(OAuthServerException $refusal): GrantRefusal
    {
        return match ($refusal->getErrorType()) {
            'invalid_scope' => GrantRefusal::InvalidScope,
            'invalid_target' => GrantRefusal::ForeignResource,
            default => self::CAUSE_OF_HINT[$refusal->getHint() ?? ''] ?? GrantRefusal::Unrecognised,
        };
    }
}
