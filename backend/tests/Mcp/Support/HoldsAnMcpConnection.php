<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Support;

use App\Tests\Mcp\Connection\DrivesTheOAuthFlow;
use App\Tests\Support\TestCredentials;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Une connexion MCP détenue par le test (#648) : accordée par le **vrai**
 * parcours de {@see DrivesTheOAuthFlow} — demande d'autorisation, consentement
 * d'un compte de confiance, échange du code au point de jeton —, jamais par
 * des lignes écrites à la main. Le jeton d'accès rendu est donc celui que
 * league émet, sous la forme et avec l'audience qu'un client reçoit ; c'est
 * ce qui fait du test de l'authentificateur Bearer un test de bout en bout.
 *
 * Usage :
 *
 *  - {@see self::holdMcpConnection()} rend la connexion
 *    ({@see HeldMcpConnection} : jeton, compte, connexion, client), les
 *    cookies du site vidés — le client MCP ne présente que son jeton ;
 *    {@see self::holdAnotherMcpConnection()} une seconde, du même compte à
 *    un autre client ;
 *    {@see self::heldMcpConnectionOf()} la lit dans la réponse du point de
 *    jeton, pour un test qui traverse lui-même les étapes du parcours ;
 *  - {@see self::bearer()} donne l'en-tête `Authorization` à passer en
 *    `server:` d'une requête (le format `$_SERVER` de `KernelBrowser`) ;
 *  - {@see self::rewriteHeldAccountRoles()}, {@see self::alterHeldConnection()} et
 *    {@see self::alterHeldAccessToken()} altèrent les lignes en SQL, entre deux
 *    requêtes, pour prouver qu'un changement en base prend effet à la
 *    requête suivante (ADR 0006 D4).
 *
 * La classe qui l'utilise appelle setUpOAuthFlow() et tearDownOAuthFlow()
 * (les tables du modèle et les comptes), plus `forgetMcpSessions()` si elle
 * ouvre des sessions MCP. Un test qui crée lui-même son `KernelBrowser`
 * l'assigne à `$this->client` et appelle registerOAuthClients() à la place
 * de setUpOAuthFlow().
 */
trait HoldsAnMcpConnection
{
    use DrivesTheOAuthFlow;

    /**
     * Le parcours entier pour un compte de confiance (TRUSTED_USERNAME) : le
     * jeton d'accès rendu par le point de jeton, et les identifiants de ses
     * lignes.
     */
    private function holdMcpConnection(): HeldMcpConnection
    {
        return $this->heldMcpConnectionOf($this->exchange($this->grantedCode()));
    }

    /**
     * Une seconde connexion du compte de confiance de
     * {@see self::holdMcpConnection()}, appelé avant : le même compte se
     * reconnecte au site et consent une seconde fois — une autre ligne
     * `mcp_connection`, un autre jeton. **À un autre client**
     * (OTHER_CLIENT_ID) : un second accord au même client remplacerait le
     * premier (#649, décision 2), et les deux connexions ne seraient plus
     * actives ensemble.
     */
    private function holdAnotherMcpConnection(): HeldMcpConnection
    {
        $this->signInAs(self::TRUSTED_USERNAME, TestCredentials::superPassword());
        $otherClient = ['client_id' => self::OTHER_CLIENT_ID];

        return $this->heldMcpConnectionOf($this->exchange($this->codeGrantedByTheSignedInAccount($otherClient), $otherClient));
    }

    /**
     * La connexion qu'une réponse du point de jeton a rendue : son jeton
     * d'accès, et les identifiants de ses lignes, lus en base par l'empreinte
     * du jeton (le seul lien que la base garde avec lui). Pour un test qui
     * traverse lui-même les étapes du parcours (un compte à lui, une lecture
     * des journaux entre deux requêtes).
     */
    private function heldMcpConnectionOf(Response $response): HeldMcpConnection
    {
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        $accessToken = $this->json($response)['access_token'] ?? null;
        self::assertIsString($accessToken);

        $row = $this->mcpRows()->fetchAssociative(
            'SELECT c.account_id, c.id AS connection_id, cl.client_id
               FROM mcp_access_token t
               JOIN mcp_connection c ON c.id = t.connection_id
               JOIN mcp_client cl ON cl.id = c.mcp_client_id
              WHERE t.token_hash = :hash',
            ['hash' => hash('sha256', $accessToken)],
        );
        self::assertIsArray($row, 'Le jeton émis n\'a pas de ligne en base.');
        self::assertIsString($row['account_id']);
        self::assertIsString($row['connection_id']);
        self::assertIsString($row['client_id']);

        // Le client MCP n'est pas le navigateur de la personne : il ne porte
        // ni BEARER ni XSRF-TOKEN, seulement son jeton.
        $this->client->getCookieJar()->clear();

        return new HeldMcpConnection($accessToken, $row['account_id'], $row['connection_id'], $row['client_id']);
    }

    /**
     * L'en-tête `Authorization: Bearer <jeton>`, au format `$_SERVER`.
     *
     * @return array{HTTP_AUTHORIZATION: string}
     */
    private static function bearer(string $accessToken): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.$accessToken];
    }

    /**
     * Réécrit les rôles **stockés** du compte de la connexion (`[]` : le
     * palier de base, `['ROLE_SUPER']` : l'administrateur sans ROLE_TRUSTED
     * stocké).
     *
     * @param list<string> $roles
     */
    private function rewriteHeldAccountRoles(HeldMcpConnection $held, array $roles): void
    {
        $this->alterMcpRows('UPDATE cpg_user SET roles = :roles WHERE id = :id', [
            'roles' => json_encode($roles, \JSON_THROW_ON_ERROR),
            'id' => $held->accountId,
        ]);
    }

    /**
     * Altère la ligne de la connexion : `$assignments` est la clause `SET`
     * (`revoked_at = now(), revocation_cause = 'client'`, `granted_at = granted_at - INTERVAL '91 days'`).
     */
    private function alterHeldConnection(HeldMcpConnection $held, string $assignments): void
    {
        $this->alterMcpRows('UPDATE mcp_connection SET '.$assignments.' WHERE id = :id', ['id' => $held->connectionId]);
    }

    /**
     * Altère la ligne du jeton d'accès, désignée par son empreinte :
     * `$assignments` est la clause `SET`.
     */
    private function alterHeldAccessToken(HeldMcpConnection $held, string $assignments): void
    {
        $this->alterMcpRows('UPDATE mcp_access_token SET '.$assignments.' WHERE token_hash = :hash', ['hash' => hash('sha256', $held->accessToken)]);
    }

    /**
     * Une écriture qui doit toucher exactement une ligne ; l'EntityManager du
     * test est vidé, pour qu'aucune instance chargée avant ne masque la ligne
     * réécrite.
     *
     * @param array<string, string> $parameters
     */
    private function alterMcpRows(string $sql, array $parameters): void
    {
        self::assertSame(1, $this->mcpRows()->executeStatement($sql, $parameters), $sql);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function mcpRows(): Connection
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }
}
