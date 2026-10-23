<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\Exception\AuthorizationRequestRejectedException;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\User\Domain\Entity\CpgUser;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Le consentement à une connexion MCP (#647, spec 0007 phase 2).
 *
 * **Une demande, un juge.** Le résumé comme la décision passent par
 * AuthorizationRequestValidatorInterface, celui du point d'autorisation : la
 * page de consentement ne fait que renvoyer ce qu'il a déjà jugé, et le
 * serveur le juge de nouveau, sans rien en avoir gardé. La décision est
 * reconstruite en requête PSR-7 depuis les seuls champs du corps
 * (ConsentDecision::authorizationQuery()), sous leurs noms exacts : aucun
 * paramètre répété ni réécrit ne peut y entrer. Tout refus du validateur
 * devient AuthorizationRequestRejectedException (400), jamais une
 * redirection : au consentement, rien ne part vers une URI qui n'a pas été
 * revalidée.
 *
 * **L'accord** crée la connexion puis, par league, son code, dans une seule
 * transaction (TransactionInterface) : le dépôt de codes de league retrouve la
 * connexion par son identifiant, elle doit être écrite avant. La ligne
 * d'audit `mcp-connection-granted` est écrite **après** le COMMIT : une
 * connexion journalisée est une connexion qui existe. **Le refus** ne crée
 * rien et n'est pas journalisé (une absence d'action n'est pas une action).
 *
 * Dans les deux cas, la redirection vers le client reçoit `iss` (RFC 9207),
 * comme les refus du point d'autorisation.
 */
final readonly class ConsentService implements ConsentServiceInterface
{
    public function __construct(
        private AuthorizationRequestValidatorInterface $validator,
        private AuthorizationCompleterInterface $completer,
        private TransactionInterface $transaction,
        private McpClientRepositoryInterface $clients,
        private McpConnectionRepositoryInterface $connections,
        private SecurityAuditLoggerInterface $auditLogger,
        private OAuthEndpoints $endpoints,
        private ClockInterface $clock,
        // La fabrique PSR-17 du bundle MCP, prise par son interface (règle de
        // mcp.md) : le framework n'en publie pas.
        #[Autowire(service: 'mcp.psr17_factory')]
        private ServerRequestFactoryInterface $requestFactory,
    ) {
    }

    public function summarize(ServerRequestInterface $request): AuthorizationRequestSummary
    {
        return $this->validated($request);
    }

    public function decide(ConsentDecision $decision, CpgUser $account): string
    {
        $summary = $this->validated($this->requestOf($decision));
        $location = $decision->approved ? $this->grant($summary, $account) : $this->completer->deny($summary);

        return IssParameter::append($location, $this->endpoints->issuer());
    }

    /**
     * La connexion et son code, ensemble ou pas du tout ; l'audit ensuite.
     */
    private function grant(AuthorizationRequestSummary $summary, CpgUser $account): string
    {
        $client = $this->clients->findOneByClientId($summary->clientId)
            ?? throw AuthorizationRequestRejectedException::clientNoLongerRegistered();
        $connection = McpConnection::grant($account, $client, $summary->scope, $this->clock->now());

        $location = $this->transaction->run(function () use ($summary, $connection): string {
            $this->connections->add($connection);

            return $this->completer->approve($summary, $connection);
        });

        $this->auditLogger->mcpConnectionGranted($account, $connection->getId(), $summary->clientId);

        return $location;
    }

    /**
     * La décision rendue au validateur comme une demande du point
     * d'autorisation : son URL et ses paramètres de requête, construits des
     * mêmes valeurs. Le validateur lit l'une (paramètres répétés ou réécrits)
     * et les autres (le reste) ; `http_build_query` n'écrit chaque nom qu'une
     * fois, sous sa forme exacte.
     */
    private function requestOf(ConsentDecision $decision): ServerRequestInterface
    {
        $query = $decision->authorizationQuery();

        return $this->requestFactory
            ->createServerRequest('GET', $this->endpoints->authorizationEndpoint().'?'.http_build_query($query))
            ->withQueryParams($query);
    }

    /**
     * @throws AuthorizationRequestRejectedException
     */
    private function validated(ServerRequestInterface $request): AuthorizationRequestSummary
    {
        try {
            return $this->validator->validate($request);
        } catch (OAuthServerException $refusal) {
            throw AuthorizationRequestRejectedException::onRevalidation($refusal);
        }
    }
}
