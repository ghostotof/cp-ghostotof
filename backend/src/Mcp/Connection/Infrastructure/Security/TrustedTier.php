<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\Security;

use App\Mcp\Connection\Application\McpConnectionPrincipal;
use App\Security\User\Domain\Entity\CpgUser;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Le palier de confiance (ADR 0003) qu'une connexion MCP exige de son compte,
 * jugé sur les rôles **stockés** et la `role_hierarchy` de `security.yaml`
 * (#648, #649) : un ROLE_SUPER sans ROLE_TRUSTED stocké l'atteint, comme
 * partout sur le site ; le palier de base ne l'atteint pas.
 *
 * Écrit une fois pour les deux juges : l'authentificateur de `/api/mcp`, à
 * chaque requête (McpBearerAuthenticator), et le rafraîchissement, au point
 * de jeton (`RefreshTokenRepository::isRefreshTokenRevoked()`) — un compte
 * déchu ne rafraîchit plus, pas seulement il n'appelle plus. Le nom du rôle
 * est {@see McpConnectionPrincipal::TRUSTED_TIER}, jamais `CpgUser::ROLE_TRUSTED`,
 * pour que ce contexte ne dépende de `Security` que par ses deux portes
 * admises (`.claude/rules/mcp-oauth.md`) ; `McpConnectionPrincipalTest`
 * compare les deux.
 */
final readonly class TrustedTier
{
    public function __construct(
        private RoleHierarchyInterface $roleHierarchy,
    ) {
    }

    /** Vrai si les rôles stockés de `$account` atteignent le palier de confiance par la hiérarchie. */
    public function reaches(CpgUser $account): bool
    {
        return \in_array(McpConnectionPrincipal::TRUSTED_TIER, $this->roleHierarchy->getReachableRoleNames($account->getRoles()), true);
    }
}
