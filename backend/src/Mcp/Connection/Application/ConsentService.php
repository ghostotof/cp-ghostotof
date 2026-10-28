<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\Exception\AuthorizationRequestRejectedException;
use App\Mcp\Connection\Domain\Exception\ConsentAccountChangedException;
use App\Mcp\Connection\Domain\Exception\ReauthenticationRequiredException;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
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
 * **La décision est liée au compte montré** (#650, F5) : le corps renvoie
 * l'UUID que le résumé a donné à la page, et un cookie d'un autre compte est
 * un 409 (ConsentAccountChangedException), avant tout effet.
 *
 * **L'accord exige une authentification récente** (MAX_AUTHENTICATION_AGE,
 * l'instant lu dans le jeton signé par AuthenticationInstantInterface) : au
 * delà, ReauthenticationRequiredException (401 typé), rien d'écrit ni de
 * journalisé.
 *
 * **L'accord** crée la connexion puis, par league, son code, dans une seule
 * transaction (TransactionInterface) : le dépôt de codes de league retrouve la
 * connexion par son identifiant, elle doit être écrite avant. La ligne
 * d'audit `mcp-connection-granted` est écrite **après** le COMMIT : une
 * connexion journalisée est une connexion qui existe. **L'accord ne révoque
 * rien** (#726, D2) : l'accord actif du même couple compte/client n'est
 * remplacé (#649, décision 2, cause `superseded`) qu'au premier échange
 * réussi du nouveau code, au point de jeton (TokenEndpoint,
 * CodeExchangeSupersessionInterface). Un accord dont le code n'est jamais
 * échangé — le client abandonne, la redirection échoue — laisse donc
 * l'ancien vivant, au lieu de priver la personne de toute connexion ; une
 * décision renvoyée après un 5xx laisse deux connexions jusqu'au premier
 * échange, qui n'en laisse qu'une. **Le refus** ne crée ni ne révoque rien et
 * n'est pas journalisé (une absence d'action n'est pas une action).
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

    public function summarize(ServerRequestInterface $request, CpgUser $account): AuthorizationRequestSummary
    {
        $summary = $this->validated($request);
        $this->refuseASessionOlderThanThePasswordChange($account);

        return $summary;
    }

    public function decide(ConsentDecision $decision, CpgUser $account): string
    {
        $summary = $this->validated($this->requestOf($decision));
        $this->refuseADecisionShownToAnotherAccount($decision, $account);
        $this->refuseASessionOlderThanThePasswordChange($account);
        $location = $decision->approved ? $this->grant($summary, $account) : $this->completer->deny($summary);

        return IssParameter::append($location, $this->endpoints->issuer());
    }

    /**
     * La décision n'est prise que pour le compte que la page a montré (#650, F5
     * de la relecture). Le résumé nomme le compte du cookie de SA requête ; si,
     * entre le résumé et la décision, un autre onglet a reconnecté la personne
     * sous un autre compte, le cookie de la décision est celui de ce dernier : le
     * nom affiché mentirait, et l'accès irait à un compte que la personne n'a pas
     * vu nommer. Le refus est un 409 typé (la page propose de relire la demande),
     * rien n'est écrit ni journalisé.
     *
     * **L'accord et le refus sont liés**, par une règle unique : une décision
     * nomme le compte pour lequel elle a été prise. Lier le refus ne protège rien
     * (il ne crée rien) mais coûte un clic à peine, et évite une exception à
     * expliquer. Jugé **après** la revalidation (une demande devenue invalide reste
     * son 400, qu'une relecture ne répare pas) et **avant** tout jugement sur le
     * compte du cookie : la décision ne le concerne pas.
     *
     * Un compte montré absent (`null`) ne correspond à aucun compte : refusé de
     * même, en défense en profondeur de la validation du corps.
     *
     * Comparaison d'UUID (`Uuid::equals()`), pas de chaînes : l'écriture en
     * majuscules d'un même UUID est le même compte (RFC 9562 §4).
     *
     * @throws ConsentAccountChangedException
     */
    private function refuseADecisionShownToAnotherAccount(ConsentDecision $decision, CpgUser $account): void
    {
        if (null === $decision->shownAccountId || !$account->getId()->equals($decision->shownAccountId)) {
            throw ConsentAccountChangedException::sinceThePageWasShown();
        }
    }

    /**
     * Une session authentifiée **avant** le dernier changement de mot de passe du
     * compte n'obtient rien du consentement — ni résumé, ni refus, ni accord (#650,
     * F2 de la relecture). Le cookie `BEARER` n'est pas révocable (ADR 0003) :
     * un attaquant qui l'a volé avant que la personne ne change son mot de passe
     * (la remédiation) garde sa session une heure, et la grâce de dix minutes
     * de l'accord lui laissait ouvrir une connexion de trente jours que la
     * révocation du changement de mot de passe ne pouvait plus couper, puisque
     * née après. Le compte lu ici est celui que le firewall vient de recharger en
     * base : sa version des identifiants est celle du moment, jamais celle d'un
     * état gardé — comparée à celle que le jeton de la session porte
     * (S1 de la seconde relecture : une égalité, plus une date, qu'une seconde
     * douteuse ou une horloge de pod faussaient).
     *
     * **Au résumé et au refus aussi**, contrairement à l'âge de l'authentification
     * (qui ne bloque que l'accord) : la remédiation d'un mot de passe volé
     * ne laisse à une session d'avant aucun usage de ce consentement, et la
     * personne que cela renvoie à la connexion y gagne une session saine.
     * Jugé **après** la revalidation de la demande, comme l'âge : une demande
     * devenue invalide reste son 400.
     *
     * **Depuis R1b, le firewall `api` refuse déjà** toute session antérieure au
     * changement de mot de passe, avant tout contrôleur
     * (SessionBeforePasswordChangeRefusalListener, même prédicat) : par HTTP ce
     * refus-ci n'est plus atteint. Il reste, comme la défense en profondeur d'une
     * porte qui accorde une connexion de trente jours, pour tout appelant qui
     * ne passerait pas par ce firewall ; il ne coûte rien à un compte sans changement.
     *
     * @throws ReauthenticationRequiredException
     */
    private function refuseASessionOlderThanThePasswordChange(CpgUser $account): void
    {
        // Le cas de presque tous les comptes : le mot de passe n'a jamais changé
        // (version 0), rien à comparer — un jeton d'avant le déploiement, sans
        // revendication, vaut 0 lui aussi —, donc aucune lecture du jeton (le
        // port revérifie sa signature) au résumé ni au refus, qui n'avaient
        // jusqu'ici aucune raison de la payer. Le firewall `api`, lui, compare
        // aussi ce cas (revendication présente et différente de 0) : cette porte
        // n'est que la défense en profondeur.
        if (0 === $account->getCredentialsVersion()) {
            return;
        }

        if ($account->claimsAStaleCredentialsVersion($this->authenticationInstant->credentialsVersion())) {
            throw ReauthenticationRequiredException::afterAPasswordChange();
        }
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
     * L'authentification récente d'abord ; puis la connexion et son code,
     * ensemble ou pas du tout ; l'audit de l'accord ensuite. Aucune
     * révocation ici : le remplacement de l'accord précédent attend l'échange
     * du code (#726, D2).
     */
    private function grant(AuthorizationRequestSummary $summary, CpgUser $account): string
    {
        $this->requireRecentAuthentication();

        $client = $this->clients->findOneByClientId($summary->clientId)
            ?? throw AuthorizationRequestRejectedException::clientNoLongerRegistered();
        $connection = McpConnection::grant($account, $client, $summary->scope, $this->clock->now());

        $location = $this->transaction->run(function () use ($summary, $connection): string {
            $this->connections->add($connection);

            return $this->completer->approve($summary, $connection);
        });

        // Après le COMMIT seulement : une transaction annulée n'a rien accordé.
        $this->auditLogger->mcpConnectionGranted($connection);

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
