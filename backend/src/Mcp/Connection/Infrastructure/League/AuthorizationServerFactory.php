<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use App\Mcp\Connection\Domain\ValueObject\ConnectionLifetimes;
use League\OAuth2\Server\AuthorizationServer;

/**
 * Construit le serveur d'autorisation de league (#647), sans le bundle :
 * `config/services.yaml` le déclare comme service
 * (`League\OAuth2\Server\AuthorizationServer`) par cette fabrique, que les
 * contrôleurs OAuth reçoivent tel quel.
 *
 * Une fabrique (Factory) parce que league se configure par mutations après
 * construction, dans un ordre qui compte : le scope par défaut et la
 * révocation des rafraîchissements sont **copiés** dans chaque grant par
 * `enableGrantType()`, ils doivent donc être posés avant — dans l'ordre
 * inverse, les grants garderaient les valeurs de league.
 *
 * - jetons d'accès opaques (OpaqueAccessToken), donc NoSigningKey ;
 * - codes et rafraîchissements chiffrés sous la clé d'OAuthEncryptionKey ;
 * - code d'autorisation à usage unique, PKCE `S256` exigé par
 *   LoopbackAwareAuthCodeGrant, seul à en décider (league admettrait `plain`
 *   et une méthode absente) ; l'exigence de league pour les clients publics
 *   reste en place derrière, jamais levée ici ;
 * - jeton d'accès et rafraîchissement, chaque rafraîchissement révoquant le
 *   précédent ; la charge d'un rafraîchissement
 *   vérifiée avant que league ne la lise (PayloadCheckedRefreshTokenGrant :
 *   un code présenté à sa place est un `invalid_grant`, pas un 500).
 *
 * Les trois durées que league applique (code, jeton d'accès,
 * rafraîchissement) sont celles de la configuration, lues dans
 * ConnectionLifetimes (#649) : aucune copie ici. Chaque lecture rend un
 * `DateInterval` neuf, aucun n'est partagé entre deux grants.
 */
final readonly class AuthorizationServerFactory
{
    public function __construct(
        private ClientRepository $clients,
        private AccessTokenRepository $accessTokens,
        private ScopeRepository $scopes,
        private AuthCodeRepository $authCodes,
        private RefreshTokenRepository $refreshTokens,
        private OAuthEncryptionKey $encryptionKey,
        private ConnectionLifetimes $lifetimes,
    ) {
    }

    public function create(): AuthorizationServer
    {
        $server = new AuthorizationServer(
            $this->clients,
            $this->accessTokens,
            $this->scopes,
            new NoSigningKey(),
            $this->encryptionKey->key(),
        );
        $server->setDefaultScope(NominativeReadScope::IDENTIFIER);
        $server->revokeRefreshTokens(true);

        $authCodeGrant = new LoopbackAwareAuthCodeGrant($this->authCodes, $this->refreshTokens, $this->lifetimes->authorizationCode());
        $authCodeGrant->setRefreshTokenTTL($this->lifetimes->refreshToken());
        $server->enableGrantType($authCodeGrant, $this->lifetimes->accessToken());

        $refreshTokenGrant = new PayloadCheckedRefreshTokenGrant($this->refreshTokens);
        $refreshTokenGrant->setRefreshTokenTTL($this->lifetimes->refreshToken());
        $server->enableGrantType($refreshTokenGrant, $this->lifetimes->accessToken());

        return $server;
    }
}
