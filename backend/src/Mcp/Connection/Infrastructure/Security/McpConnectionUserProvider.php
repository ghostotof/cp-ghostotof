<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\Security;

use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Le fournisseur `mcp_connections` du firewall `mcp` (#648), qui ne fournit
 * rien : SecurityBundle en exige un par firewall dès que plusieurs sont
 * déclarés, mais l'utilisateur vient du badge de McpBearerAuthenticator, qui
 * porte son propre chargeur (la ligne relue avec le jeton), et le firewall
 * est sans état — rien n'est jamais rechargé d'une requête à l'autre.
 *
 * Un fournisseur à lui plutôt que `cpg_users` ou `app_users` : celui-là
 * chargerait un compte par son identifiant de connexion, le seul chemin qui
 * ferait du firewall `mcp` autre chose que la relecture d'un jeton. Ici, tout
 * chargement par identifiant échoue, sans citer l'identifiant reçu (#363 :
 * le canal `security` journalise le message de l'exception).
 *
 * @implements UserProviderInterface<McpConnectionUser>
 */
final class McpConnectionUserProvider implements UserProviderInterface
{
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        throw new UserNotFoundException('An MCP connection is only loaded with its access token.');
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        throw new UnsupportedUserException('The mcp firewall is stateless: an MCP connection is never refreshed.');
    }

    public function supportsClass(string $class): bool
    {
        return McpConnectionUser::class === $class;
    }
}
