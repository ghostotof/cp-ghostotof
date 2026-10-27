<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\Exception\AuthorizationRequestRejectedException;
use App\Mcp\Connection\Domain\Exception\ReauthenticationRequiredException;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\User\Domain\Entity\CpgUser;
use DateInterval;
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
 * **L'accord exige une authentification récente** (MAX_AUTHENTICATION_AGE,
 * l'instant lu dans le jeton signé par AuthenticationInstantInterface) : au
 * delà, ReauthenticationRequiredException (401 typé), rien d'écrit ni de
 * journalisé.
 *
 * **L'accord** crée la connexion puis, par league, son code, dans une seule
 * transaction (TransactionInterface) : le dépôt de codes de league retrouve la
 * connexion par son identifiant, elle doit être écrite avant. **Il remplace
 * l'accord actif du même couple compte/client** (#649, décision 2) : dans la
 * même transaction, juste après l'ajout, les autres connexions actives du
 * couple sont révoquées, cause `superseded` — un client ré-autorisé, ou une
 * décision renvoyée après un 5xx, ne laisse qu'une connexion active. La ligne
 * d'audit `mcp-connection-granted` est écrite **après** le COMMIT, puis une
 * ligne `mcp-connection-revoked` par connexion remplacée : une connexion
 * journalisée est une connexion qui existe, une révocation journalisée une
 * révocation qui a eu lieu. **Le refus** ne crée ni ne révoque rien et n'est
 * pas journalisé (une absence d'action n'est pas une action).
 *
 * **Limite acceptée** : deux accords simultanés du même couple (un double
 * clic, que `useDeliberateActivation` rend improbable) peuvent laisser deux
 * connexions actives — chaque transaction ne voit pas la connexion encore
 * non validée de l'autre. La seconde n'a jamais servi et expire au bout du
 * délai d'inactivité. Une contrainte d'unicité partielle a été écartée : elle
 * ferait de ce double clic un 500.
 *
 * Dans les deux cas, la redirection vers le client reçoit `iss` (RFC 9207),
 * comme les refus du point d'autorisation.
 */
final readonly class ConsentService implements ConsentServiceInterface
{
    /**
     * L'âge au-delà duquel une authentification ne suffit plus à accorder une
     * connexion (F1 de la relecture de #647, décision de l'auteur) : dix
     * minutes, le temps de lire la page de consentement après s'être
     * connecté. Une constante plutôt qu'un paramètre : la même règle en
     * développement, en test, en préproduction et en production (#659), et
     * aucune configuration de sécurité propre à un environnement.
     */
    public const string MAX_AUTHENTICATION_AGE = 'PT10M';

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
        private AuthenticationInstantInterface $authenticationInstant,
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
     * L'accord seul l'exige : il ouvre une connexion de trente jours au
     * corpus nominatif, qu'un cookie `BEARER` volé (une heure, non révocable,
     * ADR 0003) ne doit pas suffire à ouvrir. Le résumé et le refus n'ouvrent
     * rien : les exiger aussi ne protégerait rien, et renverrait à la
     * connexion une personne qui veut seulement dire non. Jugé **après** la
     * demande : une demande devenue invalide reste son 400, qu'une nouvelle
     * connexion ne réparerait pas. Un instant inconnu vaut trop ancien.
     *
     * @throws ReauthenticationRequiredException
     */
    private function requireRecentAuthentication(): void
    {
        $authenticatedAt = $this->authenticationInstant->authenticatedAt();

        if (null === $authenticatedAt || $authenticatedAt->add(new DateInterval(self::MAX_AUTHENTICATION_AGE)) < $this->clock->now()) {
            throw ReauthenticationRequiredException::toGrantAConnection();
        }
    }

    /**
     * L'authentification récente d'abord ; puis la connexion, le remplacement
     * des accords précédents du couple et le code, ensemble ou pas du tout ;
     * l'audit ensuite — l'accord, puis chaque connexion remplacée.
     */
    private function grant(AuthorizationRequestSummary $summary, CpgUser $account): string
    {
        $this->requireRecentAuthentication();

        $client = $this->clients->findOneByClientId($summary->clientId)
            ?? throw AuthorizationRequestRejectedException::clientNoLongerRegistered();
        $now = $this->clock->now();
        $connection = McpConnection::grant($account, $client, $summary->scope, $now);

        $written = $this->transaction->run(function () use ($summary, $connection, $now): WrittenGrant {
            $this->connections->add($connection);
            // Dans la transaction : la nouvelle connexion, son code et les
            // anciennes révoquées, ou rien de tout cela.
            $superseded = $this->connections->revokeOtherActiveOf($connection, RevocationCause::Superseded, $now);

            return new WrittenGrant($this->completer->approve($summary, $connection), $superseded);
        });

        // Après le COMMIT seulement : une transaction annulée n'a rien révoqué.
        $this->auditLogger->mcpConnectionGranted($connection);
        foreach ($written->superseded as $revoked) {
            $this->auditLogger->mcpConnectionRevoked($revoked, $revoked->cause()->value);
        }

        return $written->location;
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
