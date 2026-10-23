<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use App\Mcp\Connection\Application\AuthorizationCompleterInterface;
use App\Mcp\Connection\Application\AuthorizationRequestSummary;
use App\Mcp\Connection\Domain\Entity\McpConnection;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * L'achèvement d'une demande d'autorisation par league (#647) : poser son
 * « utilisateur », l'accord ou le refus, puis lire la redirection vers le
 * client dans ce que league rend — une réponse PSR-7 pour un accord, une
 * OAuthServerException `access_denied` pour un refus.
 *
 * L'accord émet le code par `completeAuthorizationRequest()`, dont le dépôt
 * (AuthCodeRepository) persiste l'empreinte en retrouvant la connexion par
 * l'identifiant de ConnectionAsUser : l'appelant l'a écrite avant, dans la
 * même transaction. Le refus passe par league aussi, plutôt que par une
 * redirection écrite ici : sa forme (`error`, `error_description`, `hint`,
 * `state`) reste celle de league, comme les refus du point d'autorisation.
 *
 * La fabrique de réponses PSR-17 est celle du bundle MCP (`mcp.psr17_factory`),
 * prise par son interface, comme
 * {@see \App\Mcp\Connection\Presentation\Controller\AuthorizeController}.
 */
final readonly class LeagueAuthorizationCompleter implements AuthorizationCompleterInterface
{
    /** L'erreur OAuth d'un refus (RFC 6749 §4.1.2.1). */
    private const string ACCESS_DENIED = 'access_denied';

    public function __construct(
        private AuthorizationServer $server,
        #[Autowire(service: 'mcp.psr17_factory')]
        private ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function approve(AuthorizationRequestSummary $summary, McpConnection $connection): string
    {
        $authorization = $summary->authorizationRequest;
        $authorization->setUser(ConnectionAsUser::of($connection));
        $authorization->setAuthorizationApproved(true);

        $response = $this->server->completeAuthorizationRequest($authorization, $this->responseFactory->createResponse());

        return $this->locationOf($response) ?? throw UnexpectedAuthorizationResponseException::approvalWithoutRedirect();
    }

    public function deny(AuthorizationRequestSummary $summary): string
    {
        $authorization = $summary->authorizationRequest;
        // Exigé par league, jamais lu sur ce chemin (DeniedRequestUser).
        $authorization->setUser(new DeniedRequestUser());
        $authorization->setAuthorizationApproved(false);

        try {
            $this->server->completeAuthorizationRequest($authorization, $this->responseFactory->createResponse());
        } catch (OAuthServerException $denial) {
            // Le seul refus que league lève ici. Toute autre erreur, même
            // munie d'une redirection, est un défaut du serveur : elle ne
            // part jamais vers le client comme si la personne avait refusé.
            if (self::ACCESS_DENIED !== $denial->getErrorType()) {
                throw UnexpectedAuthorizationResponseException::denialOtherThanAccessDenied($denial);
            }

            return $this->locationOf($denial->generateHttpResponse($this->responseFactory->createResponse()))
                ?? throw UnexpectedAuthorizationResponseException::denialWithoutRedirect();
        }

        throw UnexpectedAuthorizationResponseException::denialWithoutRedirect();
    }

    private function locationOf(ResponseInterface $response): ?string
    {
        $location = $response->getHeaderLine('Location');

        return '' === $location ? null : $location;
    }
}
