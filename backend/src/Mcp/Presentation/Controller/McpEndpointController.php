<?php

declare(strict_types=1);

namespace App\Mcp\Presentation\Controller;

use App\Mcp\Infrastructure\Http\ServerAnswersInterface;
use InvalidArgumentException;
use Mcp\Exception\SessionStoreException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Server\Session\SessionStoreInterface;
use Mcp\Server\Transport\Http\MiddlewareRequestHandler;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
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
 * Le transport Streamable HTTP du SDK de la version épinglée (composer.json),
 * comme en 0.8.1, n'ouvre pas de flux serveur et répond 405 au GET —
 * conforme à la spécification MCP, mais l'AI Assistant de PhpStorm prend ce
 * 405 pour une panne et n'utilise pas le serveur (ADR 0006,
 * conséquences). Le GET reçoit donc un flux SSE vide qui demande au client
 * d'attendre cinq minutes avant de le rouvrir (`retry`), et se ferme
 * aussitôt.
 *
 * Une réponse finie, jamais un `StreamedResponse` tenu ouvert : sous PHP-FPM
 * un flux maintenu retiendrait un worker par client branché. Le serveur
 * n'envoie rien de lui-même (aucune notification), il n'y a donc rien à
 * attendre sur ce flux.
 *
 * **Le GET traverse les middlewares mêmes du POST**, ceux du SDK tels que la
 * fabrique du bundle les rend : la protection contre le rebond DNS, la seule
 * qu'elle construit avec `allowed_hosts` et `allowed_origins` de mcp.yaml, et
 * le CORS du SDK, laissé à ses défauts — aucune origine autorisée, celle du
 * site non plus : il ne pose jamais `Access-Control-Allow-Origin`. Jamais une
 * copie de leurs règles : une copie
 * réduite à un hôte refusait au GET un second hôte que le POST acceptait, et
 * lisait mal un hôte IPv6 (relecture de la tâche 2). Puis il répond comme le
 * transport pour une session : 400 si son identifiant n'est pas un UUID, 404
 * si elle n'existe pas ou plus, ou ne se relit pas (le client se
 * réinitialise), 406 sans `Accept: text/event-stream` ; un identifiant vide
 * vaut absent, comme au transport. Entre les deux, la règle d'en-tête de version du transport
 * (`ProtocolVersionMiddleware`) : 400 pour une révision qu'il ne négocie pas.
 *
 * **Et 503 quand le contrôle d'existence du store lève** (#731, décision du
 * 2026-11-06 : suivre le SDK). Le contrôle d'existence lève alors
 * {@see SessionStoreException} — le store du SDK sur un cache dont le `has()`
 * échoue, que le store borné laisse passer —, dont le SDK fait au POST un 503
 * `-32603` « Session store is unavailable. » (`Protocol::resolveSession()`).
 * Le GET rend le même, sans `id` (il ne porte aucune requête) : la session
 * existe peut-être, le client réessaie, là où un 404 le ferait se
 * réinitialiser et l'abandonner ; sortie du contrôleur, l'exception donnait
 * un 500. Une ligne `error` du même message sur le canal `mcp`, avec
 * l'exception et sans l'identifiant de session. Un GET sans identifiant à
 * contrôler (en-tête absent, vide ou « 0 ») ne consulte pas le store et reste
 * servi. Mesuré (#731) : derrière le pool DBAL de `cache.yaml`, l'adaptateur
 * de Symfony attrape lui-même l'échec de la requête (`hasItem()` rend `false`
 * après un `warning` du canal `cache`) — une base injoignable s'y lit encore
 * « session absente », 404 au GET comme au POST ; le 503 vaut pour un cache
 * dont le `has()` lève, et n'est donc pas atteint en production — limite
 * acceptée par l'auteur le 2026-11-10, suivie par l'issue #770.
 */
#[AsDecorator('mcp.server.portfolio.controller')]
final readonly class McpEndpointController
{
    /** Délai de reconnexion demandé au client, en millisecondes. */
    private const int RECONNECT_DELAY_MS = 300_000;

    private const string EVENT_STREAM = 'text/event-stream';

    /** Les valeurs de `Mcp-Session-Id` que le transport lit fausses, donc absentes. */
    private const array SESSION_IDS_READ_AS_ABSENT = ['', '0'];

    /**
     * Le message du 503 et de sa ligne de journal, mot pour mot celui du SDK
     * au POST (`Protocol::resolveSession()`).
     */
    private const string SESSION_STORE_UNAVAILABLE = 'Session store is unavailable.';

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
        // Le canal du SDK, par identifiant : la ligne du 503 s'écrit là où le
        // SDK écrit la sienne au POST, sous le même rédacteur et le même
        // handler de production (.claude/rules/mcp-journaux.md).
        #[Autowire(service: 'monolog.logger.mcp')]
        private LoggerInterface $logger,
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
        // Puis la règle d'en-tête de version, que le transport applique au
        // trafic de l'ère à poignée de main — un GET sans corps en est —, par
        // sa propre liste (#673).
        $middleware = [
            ...$this->middlewareFactory->create() ?? StreamableHttpTransport::defaultMiddleware(),
            ...StreamableHttpTransport::handshakeMiddleware(),
        ];
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

            try {
                $exists = $this->sessionStore->exists($sessionId);
            } catch (SessionStoreException $e) {
                // Le store injoignable n'est pas une session absente : elle
                // existe peut-être, et un 404 la ferait abandonner. 503, le
                // client réessaie — ce que le SDK répond au POST. Jamais
                // l'identifiant de session dans la ligne : il vaut accès à
                // la session.
                $this->logger->error(self::SESSION_STORE_UNAVAILABLE, ['exception' => $e]);

                return $this->jsonRpcServerError(Response::HTTP_SERVICE_UNAVAILABLE, self::SESSION_STORE_UNAVAILABLE);
            }

            // Une session présente mais illisible (le MAC du cache refusé
            // après une rotation d'APP_SECRET) n'existe pas pour le store
            // borné, qui la lit à ce contrôle (#673) : 404, le client se
            // réinitialise, comme au POST.
            if (!$exists) {
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

    /**
     * Le corps d'erreur JSON-RPC du transport pour une panne du serveur
     * (`-32603`), sans `id` : un GET ne porte aucune requête.
     */
    private function jsonRpcServerError(int $status, string $message): ResponseInterface
    {
        return $this->psr17Factory->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->psr17Factory->createStream(json_encode(Error::forInternalError($message), \JSON_THROW_ON_ERROR)));
    }

    private function plain(int $status, string $message): ResponseInterface
    {
        return $this->psr17Factory->createResponse($status)
            ->withHeader('Content-Type', 'text/plain')
            ->withBody($this->psr17Factory->createStream($message));
    }
}
