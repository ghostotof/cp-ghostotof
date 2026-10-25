<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\Security;

use App\Mcp\Connection\Application\McpConnectionPrincipal;
use App\Mcp\Connection\Domain\Entity\McpConnection;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * L'utilisateur du firewall `mcp` (#648) : une connexion MCP dont le jeton
 * vient d'être relu en base (ADR 0006 D4), jamais le compte lui-même.
 *
 *  - `getUserIdentifier()` est l'UUID de la **connexion** : c'est ce que
 *    l'authentification a établi, et l'identifiant de connexion du compte,
 *    souvent un prénom et un nom (#363), n'a rien à faire dans le jeton de
 *    sécurité ;
 *  - `getRoles()` sont les rôles **stockés** du compte (`CpgUser::getRoles()`,
 *    `ROLE_USER` compris), relus avec le jeton : la `role_hierarchy` se joue
 *    au vote, comme sur le firewall `api` — un ROLE_SUPER y atteint
 *    ROLE_TRUSTED ;
 *  - `grantsNominativeRead()` est le scope **stocké** de la connexion, relu
 *    avec le jeton.
 *
 * Construit par McpBearerAuthenticator, qui le rend par le chargeur du badge :
 * le firewall est sans état, l'utilisateur n'est jamais rechargé ni
 * sérialisé. Ne porte ni le jeton ni son empreinte.
 */
final readonly class McpConnectionUser implements UserInterface, McpConnectionPrincipal
{
    /**
     * @param list<string> $roles
     */
    private function __construct(
        private string $accountId,
        private Uuid $connectionId,
        private string $clientId,
        private array $roles,
        private bool $grantsNominativeRead,
    ) {
    }

    /** La connexion telle que la requête du jeton l'a hydratée, son compte et son client avec elle. */
    public static function ofConnection(McpConnection $connection): self
    {
        $account = $connection->account();

        return new self($account->accountId(), $connection->getId(), $connection->client()->getClientId(), $account->getRoles(), $connection->grantsNominativeRead());
    }

    public function accountId(): string
    {
        return $this->accountId;
    }

    public function connectionId(): Uuid
    {
        return $this->connectionId;
    }

    public function clientId(): string
    {
        return $this->clientId;
    }

    public function grantsNominativeRead(): bool
    {
        return $this->grantsNominativeRead;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return $this->roles;
    }

    /**
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        return $this->connectionId->toRfc4122();
    }
}
