<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\Log;

use App\Mcp\Infrastructure\Http\McpEndpoint;
use App\Mcp\Infrastructure\Http\ServerAnswersInterface;
use App\Shared\Infrastructure\Http\CanonicalPath;
use Mcp\Capability\RegistryInterface;
use Mcp\Event\ErrorEvent;
use Mcp\Event\NotificationEvent;
use Mcp\Event\RequestEvent as McpRequestEvent;
use Mcp\Event\ResponseEvent as McpResponseEvent;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\GetPromptRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Session\SessionInterface;
use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

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
 * {@see self::MAX_DECLARED_CHARACTERS} caractères, sans caractère de contrôle ni
 * de mise en forme. Un message invalide (une
 * méthode inconnue dans un lot) n'émet aucun événement : la classe l'observe sur
 * le canal `mcp` du SDK ({@see self::observeSdkRecord()}) et lui écrit sa ligne,
 * sans méthode.
 *
 * **La révision sans session `2026-07-28`** (#664) a la même ligne, avec les
 * mêmes champs : le SDK la sert par un autre chemin, sans aucun événement, et
 * {@see ModernExchange} la lit de la requête et de la réponse HTTP, à
 * `kernel.terminate`. Son `declaredClient` est celui que la requête déclare dans
 * son `_meta` (aucune session ne le garde), borné de même. **Sa durée n'est pas
 * la même grandeur** : celle de la requête HTTP entière (pare-feu, écouteurs,
 * envoi de la réponse ; les 30 s d'un `subscriptions/listen` avant qu'il soit
 * refusé, #675), là où l'autre ère mesure le traitement du message — une
 * statistique de durée sépare les deux ères. Elle n'est écrite que pour une
 * réponse du serveur MCP — son contrôleur ou le refus avant le SDK
 * ({@see ServerAnswersInterface}) : un 4xx du framework sous l'enveloppe moderne, du
 * JSON lui aussi, garde la ligne de repli (audit de #675, C9) — comme, limite
 * acceptée, un 5xx levé par le contrôleur ou une réponse servie qu'un écouteur
 * `kernel.response` remplacerait : la méthode et l'outil s'y perdent. Puis elle lit ce
 * que dit le classifieur du SDK : un message refusé avant le SDK sous
 * l'enveloppe moderne a donc sa ligne d'appel, avec la méthode que la fabrique
 * du SDK reconnaît (aucune pour une réponse du client).
 *
 * **Une ligne de repli** ({@see self::REQUEST_WITHOUT_CALL}) pour une requête
 * HTTP sur `/api/mcp` qui n'a traité aucun message : JSON illisible, session
 * absente ou expirée, `GET`, `DELETE`, un message de l'ère à session refusé
 * avant le SDK, un `Origin` ou un `Host` que le SDK refuse à son bord, ou un
 * message refusé avant toute ère par son classifieur (un en-tête de révision
 * moderne sans enveloppe), ou une réponse que le serveur n'a pas rendue (un
 * 404, 405 ou 400 du framework).
 * La ligne porte la méthode, le statut HTTP et la révision annoncée (une valeur
 * que le SDK connaît, sinon `null`) ; {@see self::fallbackOutcome()} en déduit
 * l'issue.
 *
 * **Jamais** un argument (le `role` du prompt est du texte libre), un en-tête
 * (`Authorization`, cookie) ni l'identifiant de session, qui vaut accès à la
 * session : la ligne est construite champ par champ, rien n'y est recopié en
 * bloc, et une requête en attente ne garde que sa méthode, son nom connu et son
 * heure de début. L'IP est lue une fois, au début de la requête HTTP : à
 * `kernel.terminate`, et pendant une réponse SSE, la pile des requêtes est déjà
 * vidée.
 *
 * Un canal à part de `mcp`, celui du SDK, plafonné à `warning` (monolog.yaml) :
 * comme `ai_usage`, la trace doit exister en production, où LOG_LEVEL écarte un
 * `info`.
 *
 * Limite acceptée : une requête dont l'échange est suspendu (sampling,
 * elicitation — aucun outil n'en fait) est écrite `protocol-error` à la fin de
 * sa requête HTTP, et sa reprise ne laisse qu'une ligne de repli.
 */
#[AsMonologProcessor(channel: 'mcp', method: 'observeSdkRecord')]
final class McpCallLog implements EventSubscriberInterface, ResetInterface
{
    public const string CALL = 'MCP call';

    public const string REQUEST_WITHOUT_CALL = 'MCP request without call';

    public const int MAX_DECLARED_CHARACTERS = 64;

    /** Les caractères de contrôle (Cc) et de mise en forme (Cf). */
    private const string INVISIBLE_CHARACTERS = '/[\p{Cc}\p{Cf}]/u';

    /** Ce que le SDK 0.8.1 écrit pour chaque message qu'il n'a pas su lire. */
    private const string SDK_INVALID_MESSAGE = 'Failed to create message.';

    /** La clé de session où le SDK copie le `clientInfo` de l'`initialize`. */
    private const string SDK_CLIENT_INFO = 'client_info';

    /**
     * Les requêtes JSON-RPC en cours, par objet : l'identifiant JSON-RPC est
     * choisi par le client, deux requêtes d'un lot peuvent le partager.
     *
     * @var array<int, array{method: string, name: ?string, startedAt: int}>
     */
    private array $pending = [];

    private ?int $httpStartedAt = null;

    private ?string $callerNetwork = null;

    /** @var array{name: ?string, version: ?string}|null */
    private ?array $declaredClient = null;

    private bool $wroteALine = false;

    public function __construct(
        #[Autowire(service: 'monolog.logger.mcp_usage')]
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'mcp.server.portfolio.registry')]
        private readonly RegistryInterface $registry,
        // Le pont du contrôleur du bundle : l'ère moderne se lit sur les
        // en-têtes tels que le SDK les reçoit.
        #[Autowire(service: 'mcp.psr_http_factory')]
        private readonly HttpMessageFactoryInterface $psrHttpFactory,
        private readonly ServerAnswersInterface $serverAnswers,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Avant tout le reste : la durée de repli couvre la requête entière.
            KernelEvents::REQUEST => ['onKernelRequest', 4096],
            // Après les autres écouteurs, qui peuvent remplacer la requête ou la
            // réponse : la clé d'attente est l'objet que la réponse portera.
            McpRequestEvent::class => ['onRequest', -1024],
            McpResponseEvent::class => ['onResponse', -1024],
            ErrorEvent::class => ['onError', -1024],
            NotificationEvent::class => ['onNotification', -1024],
            KernelEvents::TERMINATE => ['onTerminate', -1024],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest() && McpEndpoint::PATH === CanonicalPath::of($event->getRequest())) {
            $this->reset();
            $this->httpStartedAt = $this->now();
            $this->callerNetwork = TruncatedIp::tryFrom($event->getRequest()->getClientIp())?->value;
        }
    }

    public function onRequest(McpRequestEvent $event): void
    {
        $request = $event->getRequest();
        $this->pending[spl_object_id($request)] = [
            'method' => $request::getMethod(),
            'name' => $this->knownName($request),
            'startedAt' => $this->now(),
        ];
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
        $this->writeLine($event->getMethod(), null, McpCallOutcome::Success, null, null, $this->declaredClient($event->getSession()));
    }

    /**
     * Processeur du canal `mcp` : le SDK n'émet aucun événement pour un message
     * qu'il n'a pas su lire, mais il l'écrit là, un enregistrement par message.
     * Rien n'est lu de l'enregistrement que son message.
     */
    public function observeSdkRecord(LogRecord $record): LogRecord
    {
        if (null !== $this->httpStartedAt && self::SDK_INVALID_MESSAGE === $record->message) {
            $this->writeLine(null, null, McpCallOutcome::ProtocolError, Error::INVALID_REQUEST, null, null);
        }

        return $record;
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $httpStartedAt = $this->httpStartedAt;
        if (null === $httpStartedAt || McpEndpoint::PATH !== CanonicalPath::of($event->getRequest())) {
            return;
        }

        try {
            // Une requête sans réponse : une exception que le SDK a rattrapée hors
            // de ses événements, ou un échange suspendu.
            foreach ($this->pending as ['method' => $method, 'name' => $name, 'startedAt' => $startedAt]) {
                $this->writeLine($method, $name, McpCallOutcome::ProtocolError, null, $this->millisecondsSince($startedAt), null);
            }

            // La révision sans session : servie sans événement, lue de l'échange HTTP.
            if (!$this->wroteALine) {
                $this->writeModernCall($event, $httpStartedAt);
            }

            if (!$this->wroteALine) {
                $this->writeFallbackLine($event, $httpStartedAt);
            }
        } finally {
            $this->reset();
        }
    }

    public function reset(): void
    {
        $this->pending = [];
        $this->httpStartedAt = null;
        $this->callerNetwork = null;
        $this->declaredClient = null;
        $this->wroteALine = false;
    }

    private function writeFallbackLine(TerminateEvent $event, int $httpStartedAt): void
    {
        $request = $event->getRequest();
        $status = $event->getResponse()->getStatusCode();
        $version = ProtocolVersion::tryFrom((string) $request->headers->get('MCP-Protocol-Version'));

        $this->logger->info(self::REQUEST_WITHOUT_CALL, [
            'httpMethod' => $request->getMethod(),
            'httpStatus' => $status,
            'protocolVersion' => $version?->value,
            'outcome' => $this->fallbackOutcome($request->getMethod(), $status)->value,
            'durationMs' => $this->millisecondsSince($httpStartedAt),
            'ip' => $this->callerNetwork,
        ]);
    }

    /**
     * L'issue d'une requête HTTP qui n'a traité aucun message. Un `POST` n'existe
     * que pour porter des messages : sans aucun, c'est une erreur de protocole —
     * le SDK répond un JSON illisible en 200 —, sauf un 202 (des réponses du
     * client, acceptées). La révision sans session n'arrive plus ici : elle a sa
     * ligne d'appel ({@see ModernExchange}).
     */
    private function fallbackOutcome(string $httpMethod, int $status): McpCallOutcome
    {
        return match (true) {
            $status >= 400 => McpCallOutcome::ProtocolError,
            HttpRequest::METHOD_POST !== $httpMethod,
            Response::HTTP_ACCEPTED === $status => McpCallOutcome::Success,
            default => McpCallOutcome::ProtocolError,
        };
    }

    private function writeCall(Request $request, SessionInterface $session, McpCallOutcome $outcome, ?int $errorCode): void
    {
        $key = spl_object_id($request);
        $pending = $this->pending[$key] ?? null;
        unset($this->pending[$key]);

        $this->writeLine(
            $request::getMethod(),
            $pending['name'] ?? $this->knownName($request),
            $outcome,
            $errorCode,
            null === $pending ? null : $this->millisecondsSince($pending['startedAt']),
            $this->declaredClient($session),
        );
    }

    /**
     * Une requête de l'ère moderne n'a pas de session : le client déclare son
     * `clientInfo` dans le `_meta` de chaque requête, et sa durée est celle de la
     * requête HTTP qui la porte seule.
     *
     * La lecture relit l'échange avec les classes du SDK et du pont PSR-7 : ce
     * qu'elles lèvent — une requête que le pont ne sait pas convertir, que le
     * contrôleur du bundle a donc refusée avant le SDK — laisse la requête à la
     * ligne de repli plutôt que sans ligne.
     */
    private function writeModernCall(TerminateEvent $event, int $httpStartedAt): void
    {
        // Un 4xx du framework (routeur, ErrorListener) est du JSON sous `/api`,
        // qu'aucun code MCP n'a produit : il reste à la ligne de repli (C9).
        if (!$this->serverAnswers->recorded($event->getResponse())) {
            return;
        }

        try {
            $exchange = ModernExchange::tryRead($event->getRequest(), $event->getResponse(), $this->psrHttpFactory);
        } catch (Throwable) {
            return;
        }

        if (null === $exchange) {
            return;
        }

        $this->writeLine(
            $exchange->method,
            null === $exchange->request ? null : $this->knownName($exchange->request),
            $exchange->outcome,
            $exchange->errorCode,
            $this->millisecondsSince($httpStartedAt),
            $this->boundedClient($exchange->declaredClientInfo),
        );
    }

    /**
     * @param array{name: ?string, version: ?string}|null $declaredClient
     */
    private function writeLine(?string $method, ?string $name, McpCallOutcome $outcome, ?int $errorCode, ?float $durationMs, ?array $declaredClient): void
    {
        $this->wroteALine = true;

        $this->logger->info(self::CALL, [
            'method' => $method,
            'name' => $name,
            'outcome' => $outcome->value,
            'errorCode' => $errorCode,
            'durationMs' => $durationMs,
            'ip' => $this->callerNetwork,
            'declaredClient' => $declaredClient,
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

    /**
     * Le `clientInfo` que le SDK a copié dans la session à l'`initialize`, borné
     * champ par champ, lu une fois par requête HTTP (une session par requête).
     *
     * @return array{name: ?string, version: ?string}|null
     */
    private function declaredClient(SessionInterface $session): ?array
    {
        if (null !== $this->declaredClient) {
            return $this->declaredClient;
        }

        $declared = $session->get(self::SDK_CLIENT_INFO);

        return $this->declaredClient = $this->boundedClient(\is_array($declared) ? $declared : null);
    }

    /**
     * Le `clientInfo` déclaré, borné champ par champ : rien ne le vérifie.
     *
     * @param array<mixed>|null $declared
     *
     * @return array{name: ?string, version: ?string}|null
     */
    private function boundedClient(?array $declared): ?array
    {
        if (null === $declared) {
            return null;
        }

        return [
            'name' => $this->boundedDeclaration($declared['name'] ?? null),
            'version' => $this->boundedDeclaration($declared['version'] ?? null),
        ];
    }

    /**
     * Un champ déclaré, tel qu'il peut s'afficher : sans octet UTF-8 invalide, sans
     * caractère de contrôle ni de mise en forme — U+202E inverse l'affichage de
     * la suite, une ligne trompeuse à l'œil dans `kubectl logs` (audit de #664) —,
     * puis tronqué.
     */
    private function boundedDeclaration(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $visible = (string) preg_replace(self::INVISIBLE_CHARACTERS, '', mb_scrub($value, 'UTF-8'));

        return mb_substr($visible, 0, self::MAX_DECLARED_CHARACTERS, 'UTF-8');
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
