<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\Log;

use App\Shared\Infrastructure\Http\CanonicalPath;
use Mcp\Capability\RegistryInterface;
use Mcp\Event\ErrorEvent;
use Mcp\Event\NotificationEvent;
use Mcp\Event\RequestEvent as McpRequestEvent;
use Mcp\Event\ResponseEvent as McpResponseEvent;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\GetPromptRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Session\SessionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Le journal d'appels du serveur MCP, canal `mcp_usage` (spec 0007, « Journal
 * des appels », tâche 4, #643) : l'auteur mesure l'usage et repère un abus sans
 * conserver de donnée personnelle complète.
 *
 * **Une ligne `info` par message JSON-RPC traité** ({@see self::CALL}), écrite
 * sur les événements que le SDK émet par le répartiteur de Symfony : méthode,
 * outil ou prompt (seulement un nom que le serveur connaît : celui que
 * l'appelant envoie n'a pas de borne), issue ({@see McpCallOutcome}), code
 * d'erreur JSON-RPC, durée, IP tronquée ({@see TruncatedIp}), et le
 * `clientInfo` que le client a **déclaré** à l'`initialize` — d'où le nom de la
 * clé : rien ne le vérifie, chaque champ est borné à
 * {@see self::MAX_DECLARED_CHARACTERS} caractères.
 *
 * **Une ligne de repli** ({@see self::REQUEST_WITHOUT_CALL}) pour une requête
 * HTTP sur `/api/mcp` qui n'a traité aucun message : JSON illisible, session
 * absente ou expirée, `GET`, `DELETE`. Le SDK n'émet alors aucun événement ; la
 * ligne porte la méthode et le statut HTTP. Un `POST` qui n'a traité aucun
 * message est une erreur de protocole quel que soit son statut (le SDK rend une
 * erreur JSON-RPC illisible en 200) : un `POST` n'existe que pour porter des
 * messages.
 *
 * **Jamais** un argument (le `role` du prompt est du texte libre), un en-tête
 * (`Authorization`, cookie) ni l'identifiant de session, qui vaut accès à la
 * session : la ligne est construite champ par champ, rien n'y est recopié en
 * bloc.
 *
 * Un canal à part de `mcp`, celui du SDK, plafonné à `warning` (monolog.yaml) :
 * comme `ai_usage`, la trace doit exister en production, où LOG_LEVEL écarte un
 * `info`.
 */
final class McpCallLog implements EventSubscriberInterface, ResetInterface
{
    public const string CALL = 'MCP call';

    public const string REQUEST_WITHOUT_CALL = 'MCP request without call';

    public const int MAX_DECLARED_CHARACTERS = 64;

    private const string ENDPOINT = '/api/mcp';

    /**
     * Début (hrtime, ns) des requêtes JSON-RPC en cours, par identifiant : une
     * requête qui ne reçoit aucune réponse est écrite à la fin de la requête HTTP.
     *
     * @var array<string, array{request: Request, startedAt: int}>
     */
    private array $pending = [];

    private ?int $httpStartedAt = null;

    private bool $wroteALine = false;

    public function __construct(
        #[Autowire(service: 'monolog.logger.mcp_usage')]
        private readonly LoggerInterface $logger,
        private readonly RequestStack $requestStack,
        #[Autowire(service: 'mcp.server.portfolio.registry')]
        private readonly RegistryInterface $registry,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Avant tout le reste : la durée de repli couvre la requête entière.
            KernelEvents::REQUEST => ['onKernelRequest', 4096],
            McpRequestEvent::class => ['onRequest', 1024],
            // Après les autres écouteurs, qui peuvent remplacer la réponse.
            McpResponseEvent::class => ['onResponse', -1024],
            ErrorEvent::class => ['onError', -1024],
            NotificationEvent::class => ['onNotification', -1024],
            KernelEvents::TERMINATE => ['onTerminate', -1024],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest() && self::ENDPOINT === CanonicalPath::of($event->getRequest())) {
            $this->reset();
            $this->httpStartedAt = $this->now();
        }
    }

    public function onRequest(McpRequestEvent $event): void
    {
        $request = $event->getRequest();
        $this->pending[$this->keyOf($request)] = ['request' => $request, 'startedAt' => $this->now()];
    }

    public function onResponse(McpResponseEvent $event): void
    {
        $result = $event->getResponse()->result;
        $outcome = $result instanceof CallToolResult && $result->isError ? McpCallOutcome::ToolError : McpCallOutcome::Success;

        $this->writeCall($event->getRequest(), $event->getSession(), $outcome, null);
    }

    public function onError(ErrorEvent $event): void
    {
        $this->writeCall($event->getRequest(), $event->getSession(), McpCallOutcome::ProtocolError, $event->getError()->code);
    }

    public function onNotification(NotificationEvent $event): void
    {
        $this->writeLine($event->getMethod(), null, McpCallOutcome::Success, null, null, $event->getSession());
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $httpStartedAt = $this->httpStartedAt;
        if (null === $httpStartedAt || self::ENDPOINT !== CanonicalPath::of($event->getRequest())) {
            return;
        }

        // Une requête sans réponse : une exception que le SDK a rattrapée hors
        // de ses événements, ou un échange suspendu.
        foreach ($this->pending as ['request' => $request, 'startedAt' => $startedAt]) {
            $this->writeLine($request::getMethod(), $this->knownName($request), McpCallOutcome::ProtocolError, null, $this->millisecondsSince($startedAt), null);
        }

        if (!$this->wroteALine) {
            $method = $event->getRequest()->getMethod();
            $status = $event->getResponse()->getStatusCode();

            $this->logger->info(self::REQUEST_WITHOUT_CALL, [
                'httpMethod' => $method,
                'httpStatus' => $status,
                'outcome' => ('POST' === $method || $status >= 400 ? McpCallOutcome::ProtocolError : McpCallOutcome::Success)->value,
                'durationMs' => $this->millisecondsSince($httpStartedAt),
                // La pile des requêtes est déjà vidée à `kernel.terminate`.
                'ip' => $this->networkOf($event->getRequest()),
            ]);
        }

        $this->reset();
    }

    public function reset(): void
    {
        $this->pending = [];
        $this->httpStartedAt = null;
        $this->wroteALine = false;
    }

    private function writeCall(Request $request, SessionInterface $session, McpCallOutcome $outcome, ?int $errorCode): void
    {
        $key = $this->keyOf($request);
        $startedAt = $this->pending[$key]['startedAt'] ?? null;
        unset($this->pending[$key]);

        $this->writeLine(
            $request::getMethod(),
            $this->knownName($request),
            $outcome,
            $errorCode,
            null === $startedAt ? null : $this->millisecondsSince($startedAt),
            $session,
        );
    }

    private function writeLine(string $method, ?string $name, McpCallOutcome $outcome, ?int $errorCode, ?float $durationMs, ?SessionInterface $session): void
    {
        $this->wroteALine = true;

        $this->logger->info(self::CALL, [
            'method' => $method,
            'name' => $name,
            'outcome' => $outcome->value,
            'errorCode' => $errorCode,
            'durationMs' => $durationMs,
            'ip' => $this->networkOf($this->requestStack->getMainRequest()),
            'declaredClient' => null === $session ? null : $this->declaredClient($session),
        ]);
    }

    /** Le nom de l'outil ou du prompt appelé, s'il est l'un de ceux du serveur. */
    private function knownName(Request $request): ?string
    {
        return match (true) {
            $request instanceof CallToolRequest && $this->registry->hasTool($request->name) => $request->name,
            $request instanceof GetPromptRequest && $this->registry->hasPrompt($request->name) => $request->name,
            default => null,
        };
    }

    private function networkOf(?HttpRequest $request): ?string
    {
        return TruncatedIp::tryFrom($request?->getClientIp())?->value;
    }

    /**
     * Le `clientInfo` que le SDK a copié dans la session à l'`initialize`, borné
     * champ par champ.
     *
     * @return array{name: ?string, version: ?string}|null
     */
    private function declaredClient(SessionInterface $session): ?array
    {
        $declared = $session->get('client_info');
        if (!\is_array($declared)) {
            return null;
        }

        return [
            'name' => $this->boundedDeclaration($declared['name'] ?? null),
            'version' => $this->boundedDeclaration($declared['version'] ?? null),
        ];
    }

    private function boundedDeclaration(mixed $value): ?string
    {
        return \is_string($value) ? mb_substr(mb_scrub($value, 'UTF-8'), 0, self::MAX_DECLARED_CHARACTERS, 'UTF-8') : null;
    }

    private function keyOf(Request $request): string
    {
        return get_debug_type($request->getId()).':'.$request->getId();
    }

    private function millisecondsSince(int $startedAt): float
    {
        return round(($this->now() - $startedAt) / 1_000_000, 3);
    }

    /** Horloge monotone, en nanosecondes (`hrtime()` n'est un flottant que sur 32 bits). */
    private function now(): int
    {
        return (int) hrtime(true);
    }
}
