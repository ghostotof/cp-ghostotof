<?php

declare(strict_types=1);

namespace App\Mcp\Presentation\Controller;

use App\Mcp\Infrastructure\Http\ServerAnswersInterface;
use InvalidArgumentException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Server\Session\SessionStoreInterface;
use Mcp\Server\Transport\Http\MiddlewareRequestHandler;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\AI\McpBundle\Controller\McpController;
use Symfony\AI\McpBundle\Http\MiddlewareFactory;
use Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Point d'entrée de `/api/mcp` : répond lui-même au GET (et au HEAD, que le
 * routeur fait correspondre au GET), délègue le reste au contrôleur du bundle.
 *
 * Le transport Streamable HTTP du SDK 0.8.1 n'ouvre pas de flux serveur et
 * répond 405 au GET — conforme à la spécification MCP, mais l'AI Assistant de
 * PhpStorm prend ce 405 pour une panne et n'utilise pas le serveur (ADR 0006,
 * conséquences). Le GET reçoit donc un flux SSE vide qui demande au client
 * d'attendre cinq minutes avant de le rouvrir (`retry`), et se ferme
 * aussitôt.
 *
 * Une réponse finie, jamais un `StreamedResponse` tenu ouvert : sous PHP-FPM
 * un flux maintenu retiendrait un worker par client branché. Le serveur
 * n'envoie rien de lui-même (aucune notification), il n'y a donc rien à
 * attendre sur ce flux.
 *
 * **Le GET traverse les middlewares mêmes du POST** — la protection contre le
 * rebond DNS et le CORS du SDK, que le bundle construit avec la liste
 * `allowed_hosts` de mcp.yaml —, jamais une copie de leurs règles : une copie
 * réduite à un hôte refusait au GET un second hôte que le POST acceptait, et
 * lisait mal un hôte IPv6 (relecture de la tâche 2). Puis il répond comme le
 * transport pour une session : 400 si son identifiant n'est pas un UUID, 404
 * si elle n'existe pas ou plus (le client se réinitialise), 406 sans
 * `Accept: text/event-stream` ; un identifiant vide vaut absent, comme au
 * transport.
 */
#[AsDecorator('mcp.server.portfolio.controller')]
final readonly class McpEndpointController
{
    /** Délai de reconnexion demandé au client, en millisecondes. */
    private const int RECONNECT_DELAY_MS = 300_000;

    private const string EVENT_STREAM = 'text/event-stream';

    /** Les valeurs de `Mcp-Session-Id` que le transport lit fausses, donc absentes. */
    private const array SESSION_IDS_READ_AS_ABSENT = ['', '0'];

    public function __construct(
        #[AutowireDecorated]
        private McpController $inner,
        #[Autowire(service: 'mcp.server.portfolio.middleware_factory')]
        private MiddlewareFactory $middlewareFactory,
        #[Autowire(service: 'mcp.psr_http_factory')]
        private HttpMessageFactoryInterface $psrHttpFactory,
        #[Autowire(service: 'mcp.http_foundation_factory')]
        private HttpFoundationFactoryInterface $httpFoundationFactory,
        // Le Http\Discovery\Psr17Factory du bundle, par ses interfaces PSR-17 :
        // sa classe vient de php-http/discovery, que le `require` ne porte pas
        // (#663).
        #[Autowire(service: 'mcp.psr17_factory')]
        private ResponseFactoryInterface&StreamFactoryInterface $psr17Factory,
        #[Autowire(service: 'mcp.server.portfolio.session.store')]
        private SessionStoreInterface $sessionStore,
        private ServerAnswersInterface $serverAnswers,
    ) {
    }

    /**
     * Chaque réponse rendue est inscrite dans {@see ServerAnswersInterface} : le journal
     * d'appels n'attribue au protocole que ce que le serveur a répondu.
     */
    public function handle(Request $request): Response
    {
        return $this->serverAnswers->record($this->answer($request));
    }

    private function answer(Request $request): Response
    {
        if (!\in_array($request->getMethod(), [Request::METHOD_GET, Request::METHOD_HEAD], true)) {
            return $this->inner->handle($request);
        }

        // `null` : allowed_hosts non configuré, les middlewares par défaut du
        // SDK (localhost seulement), comme le transport.
        $middleware = $this->middlewareFactory->create() ?? StreamableHttpTransport::defaultMiddleware();
        $response = new MiddlewareRequestHandler($middleware, $this->eventStream(...))
            ->handle($this->psrHttpFactory->createRequest($request));

        return $this->httpFoundationFactory->createResponse($response);
    }

    /**
     * Ce que le GET rend une fois l'hôte admis par les middlewares.
     */
    private function eventStream(ServerRequestInterface $request): ResponseInterface
    {
        if (!str_contains(strtolower($request->getHeaderLine('Accept')), self::EVENT_STREAM)) {
            return $this->plain(Response::HTTP_NOT_ACCEPTABLE, 'Not Acceptable: the client must accept text/event-stream.');
        }

        $sessionIds = $request->getHeader(StreamableHttpTransport::SESSION_HEADER);
        if (\count($sessionIds) > 1) {
            return $this->jsonRpcError(Response::HTTP_BAD_REQUEST, StreamableHttpTransport::SESSION_HEADER.' header must not be repeated.');
        }

        // Vide ou « 0 », l'en-tête vaut absent, comme au transport du SDK
        // (`$sessionIdString ? Uuid::fromString(…) : null`) : servi comme un
        // GET sans session, pas refusé comme un UUID invalide (#663).
        if ([] !== $sessionIds && !\in_array($sessionIds[0], self::SESSION_IDS_READ_AS_ABSENT, true)) {
            try {
                $sessionId = Uuid::fromString($sessionIds[0]);
            } catch (InvalidArgumentException) {
                return $this->jsonRpcError(Response::HTTP_BAD_REQUEST, StreamableHttpTransport::SESSION_HEADER.' header must be a valid UUID.');
            }

            if (!$this->sessionStore->exists($sessionId)) {
                return $this->jsonRpcError(Response::HTTP_NOT_FOUND, 'Session not found or has expired.');
            }
        }

        return $this->psr17Factory->createResponse(Response::HTTP_OK)
            ->withHeader('Content-Type', self::EVENT_STREAM)
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($this->psr17Factory->createStream(\sprintf("retry: %d\n\n", self::RECONNECT_DELAY_MS)));
    }

    /**
     * Le corps d'erreur JSON-RPC du transport, aux mêmes statuts.
     */
    private function jsonRpcError(int $status, string $message): ResponseInterface
    {
        return $this->psr17Factory->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->psr17Factory->createStream(json_encode(Error::forInvalidRequest($message), \JSON_THROW_ON_ERROR)));
    }

    private function plain(int $status, string $message): ResponseInterface
    {
        return $this->psr17Factory->createResponse($status)
            ->withHeader('Content-Type', 'text/plain')
            ->withBody($this->psr17Factory->createStream($message));
    }
}
