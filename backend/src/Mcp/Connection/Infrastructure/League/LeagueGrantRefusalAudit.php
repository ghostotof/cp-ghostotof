<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use App\Mcp\Connection\Application\FormEncodedRequest;
use App\Mcp\Connection\Application\GrantRefusalAuditInterface;
use App\Mcp\Connection\Application\OAuthProfile;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
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
 *   `client_id` d'un client enregistré, tel que league l'a lu ;
 * - un artefact illisible, ou dont la charge ne porte pas un UUID de
 *   connexion ;
 * - une connexion qui n'est plus en base (supprimée avec son compte, purgée) :
 *   personne à désigner ;
 * - un autre grant que `authorization_code` et `refresh_token`, qui ne porte
 *   aucun artefact (league le refuse `unsupported_grant_type`).
 *
 * Coût : un déchiffrement de plus que celui de league sur **tout** refus 4xx
 * d'un client connu qui porte un artefact, authentique ou non — il faut
 * déchiffrer pour le savoir ; une requête SQL native en plus pour un artefact
 * authentique seulement, l'EntityManager étant fermé par la transaction
 * annulée. Le rejeu non prouvé (recordUnprovenReplay(), #726) ne déchiffre
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
        private SecurityAuditLoggerInterface $auditLogger,
    ) {
    }

    public function record(ServerRequestInterface $request, OAuthServerException $refusal): void
    {
        if ($refusal->getHttpStatusCode() >= self::SERVER_FAULT || 'invalid_client' === $refusal->getErrorType()) {
            return;
        }

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

        $this->write($connectionId, $grantType, LeagueGrantRefusals::causeOf($refusal), $presentingClientId);
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
