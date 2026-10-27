<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use App\Mcp\Connection\Application\GrantRefusalAuditInterface;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ServerRequestInterface;

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
 * - un client inconnu (`invalid_client`) : refusé pour lui-même, avant
 *   l'artefact, il ne désigne rien — et le `presentingClientId` d'une ligne
 *   écrite est donc toujours celui d'un client enregistré ;
 * - un artefact illisible, ou dont la charge ne porte pas un UUID de
 *   connexion ;
 * - une connexion qui n'est plus en base (supprimée avec son compte, purgée) :
 *   personne à désigner ;
 * - un autre grant que `authorization_code` et `refresh_token`, qui ne porte
 *   aucun artefact (league le refuse `unsupported_grant_type`).
 *
 * Coût, payé sur un refus d'artefact authentique seulement : un déchiffrement
 * de plus que celui de league, et une requête SQL native — l'EntityManager
 * est fermé par la transaction annulée.
 */
final readonly class LeagueGrantRefusalAudit implements GrantRefusalAuditInterface
{
    /** Le paramètre qui porte l'artefact, par grant (RFC 6749 §4.1.3, §6). */
    private const array ARTIFACT_PARAMETER = [
        'authorization_code' => 'code',
        'refresh_token' => 'refresh_token',
    ];

    public function __construct(
        private GrantPayloadReader $payloadReader,
        private McpConnectionRepositoryInterface $connections,
        private SecurityAuditLoggerInterface $auditLogger,
    ) {
    }

    public function record(ServerRequestInterface $request, OAuthServerException $refusal): void
    {
        if ('invalid_client' === $refusal->getErrorType()) {
            return;
        }

        $body = $request->getParsedBody();
        if (!\is_array($body)) {
            return;
        }

        $grantType = $body['grant_type'] ?? null;
        $parameter = \is_string($grantType) ? (self::ARTIFACT_PARAMETER[$grantType] ?? null) : null;
        $artifact = null !== $parameter ? ($body[$parameter] ?? null) : null;
        if (!\is_string($grantType) || !\is_string($artifact)) {
            return;
        }

        $connectionId = $this->payloadReader->connectionOf($artifact);
        $connection = null !== $connectionId ? $this->connections->referenceOf($connectionId) : null;
        if (null === $connection) {
            return;
        }

        // Le `client_id` du corps, que league a authentifié avant l'artefact
        // (il le lit avant HTTP Basic) ; null pour un client qui ne se
        // désigne qu'en HTTP Basic.
        $presentingClientId = $body['client_id'] ?? null;

        $this->auditLogger->mcpGrantRefused(
            $connection,
            $grantType,
            LeagueGrantRefusals::causeOf($refusal)->value,
            \is_string($presentingClientId) ? $presentingClientId : null,
        );
    }
}
