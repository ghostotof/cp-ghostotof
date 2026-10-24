<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use SensitiveParameter;

/**
 * Un seul scope, `nominative:read` (spec 0007 phase 2) : tout autre est un
 * `invalid_scope`, et ce qui est accordé n'est jamais que lui.
 */
final readonly class ScopeRepository implements ScopeRepositoryInterface
{
    public function getScopeEntityByIdentifier(string $identifier): ?ScopeEntityInterface
    {
        return NominativeReadScope::IDENTIFIER === $identifier ? new NominativeReadScope() : null;
    }

    /**
     * Le scope accordé est celui de la connexion, quel que soit ce que la
     * demande portait : league a déjà refusé tout identifiant inconnu
     * (getScopeEntityByIdentifier()), et le scope par défaut du serveur
     * remplace une demande sans scope.
     *
     * @return list<ScopeEntityInterface>
     */
    public function finalizeScopes(
        array $scopes,
        string $grantType,
        ClientEntityInterface $clientEntity,
        ?string $userIdentifier = null,
        #[SensitiveParameter] ?string $authCodeId = null,
    ): array {
        return [new NominativeReadScope()];
    }
}
