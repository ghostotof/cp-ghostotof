<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use App\Mcp\Connection\Application\FormEncodedRequest;
use App\Mcp\Connection\Application\GrantRefusalAuditInterface;
use App\Mcp\Connection\Application\OAuthProfile;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\AllowedClientDomains;
use App\Mcp\Connection\Domain\ValueObject\GrantRefusal;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Uid\Uuid;

/**
 * L'audit des refus du point de jeton (#725) : **tout refus d'un code ou d'un
 * jeton de rafraîchissement authentique** écrit `mcp-grant-refused`.
 *
 * Authentique : le serveur seul détient la clé qui le chiffre
 * (GrantPayloadReader), un artefact qu'il déchiffre est un artefact qu'il a
 * émis, et son `user_id` désigne sa connexion. C'est le critère de
 * `mcp-access-token-refused` (un jeton connu) : seul qui détient un vrai
 * code ou jeton écrit une ligne, et un flot d'artefacts quelconques n'en
 * écrit aucune — ce qui borne le volume à la zone nginx `oauth` (10 par
 * minute et une rafale de 10, par IP et par pod), décision de #725.
 *
 * Aucune ligne, et la raison :
 * - un refus de statut 5xx (SERVER_FAULT) : une panne, pas un refus de
 *   l'artefact ;
 * - un client inconnu (`invalid_client`) : refusé pour lui-même, avant
 *   l'artefact, il ne désigne rien. TokenEndpoint refuse aussi ainsi un
 *   client absent du corps, et FormEncodedRequest une valeur entourée de
 *   blancs : le `presentingClientId` d'une ligne écrite est donc toujours le
 *   `client_id` d'un client enregistré (#652). `invalid_client` n'est tracé
 *   que dans un cas : le refus d'une ligne pré-enregistrée masquante
 *   (règle D-C, ClientRepository), pour un artefact authentique ;
 * - un artefact illisible, ou dont la charge ne porte pas un UUID de
 *   connexion ;
 * - une connexion qui n'est plus en base (supprimée avec son compte, purgée) :
 *   personne à désigner ;
 * - un autre grant que `authorization_code` et `refresh_token`, qui ne porte
 *   aucun artefact (league le refuse `unsupported_grant_type`).
 *
 * Ligne masquante (#652) : une ligne `preregistered` dont le `client_id` est
 * celui d'un domaine vérifié (AllowedClientDomains::isShadowedBy()) n'est pas
 * servie par ClientRepository, league répond `invalid_client` sans avoir
 * authentifié personne. Si l'artefact présenté est authentique et que le
 * `client_id` présenté est une telle ligne, la ligne porte la cause
 * GrantRefusal::PreregisteredRowShadowsVerifiedDomain, qui ne passe pas par
 * LeagueGrantRefusals::causeOf() : elle ne dépend pas de l'indication de
 * league. Client croisé : l'artefact peut être celui d'un autre client A que
 * la ligne masquante B présentée ; `clientId` est alors celui de la connexion
 * (A, ce que l'artefact désigne) et `presentingClientId` celui de B.
 *
 * Coût : un déchiffrement de plus que celui de league sur **tout** refus 4xx
 * qui porte un artefact, authentique ou non — il faut déchiffrer pour le
 * savoir, `invalid_client` compris ; une requête SQL native en plus pour un
 * artefact authentique seulement (la nature du client présenté sur un
 * `invalid_client`, puis la référence de la connexion), l'EntityManager étant
 * fermé par la transaction annulée. Le rejeu non prouvé (recordUnprovenReplay(), #726) ne déchiffre
 * rien : sa connexion est celle de la compromission ; sa requête reste.
 */
final readonly class LeagueGrantRefusalAudit implements GrantRefusalAuditInterface
{
    /** Le paramètre qui porte l'artefact, par grant (RFC 6749 §4.1.3, §6). */
    private const array ARTIFACT_PARAMETER = [
        OAuthProfile::AUTHORIZATION_CODE_GRANT => 'code',
        OAuthProfile::REFRESH_TOKEN_GRANT => 'refresh_token',
    ];

    /**
     * À partir de ce statut, league ne refuse pas l'artefact, il tombe en
     * panne — parfois **après** l'avoir validé (une collision d'identifiant à
     * l'écriture d'un jeton, `UniqueTokenIdentifierConstraintViolationException`,
     * `access_denied` en 500 ; un `server_error`) : l'auditer désignerait un
     * compte légitime pour un code valide.
     */
    private const int SERVER_FAULT = 500;

    public function __construct(
        private GrantPayloadReader $payloadReader,
        private McpConnectionRepositoryInterface $connections,
        private McpClientRepositoryInterface $clients,
        private AllowedClientDomains $allowedDomains,
        private SecurityAuditLoggerInterface $auditLogger,
    ) {
    }

    /**
     * Sauf une faute serveur, un grant sans artefact ou un artefact
     * inauthentique, écrit la ligne du refus : cause classée par
     * LeagueGrantRefusals, ou, sur `invalid_client`, seulement la cause de la
     * ligne masquante — tout autre `invalid_client` ne laisse rien.
     */
    public function record(ServerRequestInterface $request, OAuthServerException $refusal): void
    {
        if ($refusal->getHttpStatusCode() >= self::SERVER_FAULT) {
            return;
        }
        $clientRefused = 'invalid_client' === $refusal->getErrorType();

        $grantType = FormEncodedRequest::stringParameter($request, 'grant_type');
        $parameter = null !== $grantType ? (self::ARTIFACT_PARAMETER[$grantType] ?? null) : null;
        if (null === $grantType || null === $parameter) {
            return;
        }

        $artifact = FormEncodedRequest::stringParameter($request, $parameter);
        // Le `client_id` du corps, que league a authentifié avant l'artefact :
        // lu avant de déchiffrer, comme la ligne l'exige.
        $presentingClientId = FormEncodedRequest::stringParameter($request, 'client_id');
        if (null === $artifact || null === $presentingClientId) {
            return;
        }

        $connectionId = $this->payloadReader->connectionIdOf($artifact);
        if (null === $connectionId) {
            return;
        }

        if ($clientRefused) {
            // Le client n'a pas été authentifié : seul le refus d'une ligne
            // pré-enregistrée masquante (règle D-C, #652) désigne la
            // connexion de l'artefact authentique ; tout autre `invalid_client`
            // (client inconnu) ne désigne rien.
            if (!$this->isShadowingRow($presentingClientId)) {
                return;
            }
            $this->write($connectionId, $grantType, GrantRefusal::PreregisteredRowShadowsVerifiedDomain, $presentingClientId);

            return;
        }

        $this->write($connectionId, $grantType, LeagueGrantRefusals::causeOf($refusal), $presentingClientId);
    }

    /**
     * La ligne du client présenté masque-t-elle le client CIMD d'un domaine
     * vérifié ? `McpClientRepositoryInterface::registrationOf()` passe par
     * DBAL (SQL natif) et non par l'ORM : la transaction annulée a fermé
     * l'EntityManager. Un client inconnu (aucune ligne) ne masque rien.
     */
    private function isShadowingRow(string $presentingClientId): bool
    {
        $registration = $this->clients->registrationOf($presentingClientId);

        return null !== $registration && $this->allowedDomains->isShadowedBy($registration, $presentingClientId);
    }

    /**
     * La connexion est connue de l'appelant (la compromission) : aucun
     * déchiffrement, une requête pour sa référence. Le grant est celui du
     * code, le seul qui lève un rejeu.
     */
    public function recordUnprovenReplay(ServerRequestInterface $request, Uuid $connectionId): void
    {
        $presentingClientId = FormEncodedRequest::stringParameter($request, 'client_id');
        if (null === $presentingClientId) {
            return;
        }

        $this->write($connectionId, OAuthProfile::AUTHORIZATION_CODE_GRANT, GrantRefusal::ReplayUnproven, $presentingClientId);
    }

    /**
     * La ligne, pour une connexion encore en base. Une requête SQL native :
     * l'EntityManager est fermé par la transaction annulée.
     */
    private function write(Uuid $connectionId, string $grantType, GrantRefusal $cause, string $presentingClientId): void
    {
        $connection = $this->connections->referenceOf($connectionId);
        if (null === $connection) {
            return;
        }

        $this->auditLogger->mcpGrantRefused(
            connection: $connection,
            grantType: $grantType,
            cause: $cause->value,
            presentingClientId: $presentingClientId,
        );
    }
}
