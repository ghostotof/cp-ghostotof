<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use App\Mcp\Connection\Application\CodeExchangeSupersessionInterface;
use App\Mcp\Connection\Application\Supersession;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Le remplacement au premier échange d'un code (#726, D2) : la connexion que
 * la charge du code désigne est gardée, les autres connexions actives de son
 * couple compte/client sont révoquées, cause `superseded`.
 *
 * Le corps lu est celui que FormEncodedRequest a tiré du corps brut contrôlé,
 * le même que league a lu ; la connexion, celle que GrantPayloadReader
 * déchiffre (le serveur seul détient la clé). Appelé après une réponse de
 * league, donc après un échange validé : le code était authentique, vivant,
 * lié à son client et prouvé par son vérificateur PKCE.
 *
 * **L'instance gérée** : `findOneById()` rend celle que
 * `AuthCodeRepository::isAuthCodeRevoked()` a relue et verrouillée `FOR
 * UPDATE` dans la même transaction (la carte d'identité de Doctrine) ; son
 * compte et son client y sont joints. Un rafraîchissement, comme toute
 * autre grant, ne remplace rien : seul l'échange d'un code prouve que le
 * client a reçu la nouvelle connexion.
 *
 * Coût, à chaque échange réussi seulement : un déchiffrement de plus que
 * league, une requête de lecture et l'`UPDATE` de revokeOtherActiveOf(),
 * servi par l'index partiel des couples actifs.
 */
final readonly class LeagueCodeExchangeSupersession implements CodeExchangeSupersessionInterface
{
    public function __construct(
        private GrantPayloadReader $payloadReader,
        private McpConnectionRepositoryInterface $connections,
        private ClockInterface $clock,
    ) {
    }

    public function supersedeOthersOf(ServerRequestInterface $request): ?Supersession
    {
        $body = $request->getParsedBody();
        $grantType = \is_array($body) ? ($body['grant_type'] ?? null) : null;
        $code = \is_array($body) ? ($body['code'] ?? null) : null;
        if (OAuthClient::AUTHORIZATION_CODE_GRANT !== $grantType || !\is_string($code)) {
            return null;
        }

        $connectionId = $this->payloadReader->connectionIdOf($code);
        $kept = null !== $connectionId ? $this->connections->findOneById($connectionId) : null;
        if (null === $kept) {
            return null;
        }

        // L'identifiant de l'instance gérée, celle que league a verrouillée :
        // la connexion qui remplace, nommée par chaque ligne d'audit (B3).
        return new Supersession(
            $kept->getId(),
            $this->connections->revokeOtherActiveOf($kept, RevocationCause::Superseded, $this->clock->now()),
        );
    }
}
