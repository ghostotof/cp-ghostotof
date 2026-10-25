<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\Http;

use App\Mcp\Application\ProtectedTools;
use App\Mcp\Connection\Application\McpConnectionPrincipal;
use App\Shared\Infrastructure\Http\CanonicalPath;
use JsonException;
use Mcp\Exception\InvalidInputMessageException;
use Mcp\JsonRpc\MessageFactory;
use Mcp\Schema\JsonRpc\MessageInterface;
use Mcp\Schema\Request\CallToolRequest;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * L'autorisation différée de l'outil nominatif (#648, ADR 0006 D3, spec 0007
 * « Sans connexion ») : sans connexion MCP, un `tools/call` de
 * {@see ProtectedTools::NOMINATIVE_CORPUS} reçoit, **avant le SDK**, un 401
 * sans corps portant le défi `WWW-Authenticate`
 * ({@see \App\Mcp\Connection\Application\BearerChallenge::missingToken()} :
 * l'URL des métadonnées de la ressource et le scope, sans `error=` puisqu'aucun
 * jeton n'a été présenté) —
 * c'est ce qui fait ouvrir le parcours OAuth aux clients Claude. Rien du
 * catalogue ne change : l'outil reste listé, les autres outils restent servis
 * sans connexion.
 *
 * **Après le firewall** (priorité 7, sous les 8 du FirewallListener) : le
 * jeton d'une connexion valide y est déjà relu, et l'utilisateur du
 * `TokenStorage` est alors un {@see McpConnectionPrincipal} — l'appel passe.
 * Un jeton présenté et refusé n'arrive jamais ici : le firewall a déjà
 * répondu son 401 `invalid_token`. Et après les refus d'avant le SDK
 * ({@see InboundMessageRefusalListener}, 15) : un lot de plusieurs messages
 * reste leur 400.
 *
 * **Le corps décide, lu par la fabrique de messages du SDK**
 * (`MessageFactory::create()`, comme
 * {@see \App\Mcp\Infrastructure\Log\ModernExchange} la lit) : le message est
 * l'appel de l'outil nominatif pour ce serveur exactement quand le SDK en
 * ferait un CallToolRequest de ce nom, dans une session comme sous
 * l'enveloppe sans session de 2026-07-28. Un lot d'un message est jugé comme
 * un message seul : servi par le SDK dans une session, refusé par lui sous
 * l'enveloppe 2026-07-28 — que ce 401 précède, comme il précède la validation
 * de la session et le contrôle `Host` du SDK. **Jamais l'en-tête `Mcp-Name`** :
 * choisi par l'appelant, et ce 401 précède le contrôle du SDK qui le confronte
 * au corps (SEP-2243). Un corps que la fabrique ne lit pas (JSON illisible)
 * est laissé au SDK, qui le refuse lui-même.
 *
 * **Le marqueur `openai/`** : un appel dont le `_meta` porte une clé de ce
 * préfixe (ChatGPT) passe jusqu'à l'outil, qui rend le refus dans la forme que
 * ce client lit. Il ne choisit que la forme du refus, jamais l'accès : l'outil
 * fait lire l'identité par `Security`, que seul le firewall pose (ADR 0006
 * D4), à {@see \App\Mcp\Application\NominativeCorpusReaderInterface}.
 *
 * La réponse est celle d'{@see UnauthorizedAnswers}, qui l'inscrit pour le
 * journal d'appels (`mcp_usage`) comme une réponse du serveur.
 */
#[AsEventListener(event: RequestEvent::class, priority: 7)]
final readonly class DeferredAuthorizationListener
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private UnauthorizedAnswers $unauthorized,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // Chemin décodé (CanonicalPath, issue #77) : `/api/%6Dcp` est routé
        // vers le serveur MCP, il reçoit le défi de même.
        if (Request::METHOD_POST !== $request->getMethod() || McpEndpoint::PATH !== CanonicalPath::of($request)) {
            return;
        }

        // L'identité d'abord : une connexion authentifiée ne paie pas la
        // lecture du corps.
        if ($this->tokenStorage->getToken()?->getUser() instanceof McpConnectionPrincipal) {
            return;
        }

        if (!$this->callsTheNominativeToolUnmarked($request->getContent())) {
            return;
        }

        $event->setResponse($this->unauthorized->missingToken());
    }

    /**
     * Vrai quand l'un des messages du corps, tel que la fabrique du SDK le
     * construit, appelle l'outil nominatif sans marqueur `openai/`.
     */
    private function callsTheNominativeToolUnmarked(string $body): bool
    {
        try {
            $messages = MessageFactory::make()->create($body);
        } catch (JsonException) {
            // JSON illisible : le SDK le refusera lui-même (`-32700`). La
            // fabrique rattrape tout le reste en InvalidInputMessageException,
            // sauf le TypeError d'une notification au `_meta` scalaire, que
            // InboundMessageRefusalListener (priorité 15) a déjà refusé : rien
            // d'autre n'est attendu ici, et un nouveau cas sortirait en 500,
            // visible, plutôt que d'être tu.
            return false;
        }

        return array_any($messages, static fn (MessageInterface|InvalidInputMessageException $message): bool => $message instanceof CallToolRequest
            && ProtectedTools::NOMINATIVE_CORPUS === $message->name
            && !self::carriesAnOpenAiMarker($message->getMeta() ?? []));
    }

    /**
     * @param array<array-key, mixed> $meta le `_meta` du message, tel que la fabrique le garde
     */
    private static function carriesAnOpenAiMarker(array $meta): bool
    {
        return array_any(array_keys($meta), static fn (int|string $key): bool => str_starts_with((string) $key, ProtectedTools::OPENAI_META_PREFIX));
    }
}
