<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\Http;

use App\Shared\Infrastructure\Http\CanonicalPath;
use JsonException;
use Mcp\Schema\JsonRpc\Error;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Refuse en 400 sur `/api/mcp`, avant le SDK, ce que le SDK servirait ou
 * recopierait en session sans borne (#644) — {@see InboundMessageRefusal} :
 *
 * - **un lot JSON-RPC de plusieurs messages.** Le SDK 0.8.1 en accepte
 *   jusqu'à 100 par requête HTTP, une borne codée en dur que le bundle ne rend
 *   pas configurable (`MessageFactory::DEFAULT_MAX_BATCH_SIZE`) : 100
 *   `tools/call` — autant d'appels de provider et de lignes `mcp_usage` — pour
 *   une seule requête comptée par la zone nginx `mcp`. Les lots ont disparu du
 *   protocole à la révision 2025-06-18, la seule négociée en handshake. Le
 *   prédicat est celui du SDK (`MessageFactory::create()`) : `json_decode` en
 *   tableau associatif, puis toute liste PHP est un lot — un objet aux clés
 *   `"0"`, `"1"`… aussi, qui passait quand seul le crochet était regardé
 *   (relectures de #644, mesuré). Un lot d'un message coûte un message : il
 *   passe, mais son message est jugé comme les autres ;
 * - **un `initialize` qui déclare trop.** Le SDK recopie `clientInfo` et
 *   `capabilities` tels quels en session (`InitializeHandler`), réécrite en
 *   entier à chaque requête : un client anonyme y faisait tenir 16 Kio de
 *   corps, trois fois plus une fois encodés ;
 * - **une réponse du client.** Le SDK range chaque réponse en session sous
 *   l'identifiant que le client choisit (`Protocol::handleResponse()`), et
 *   elles s'accumulent jusqu'à la borne de session : la ligne réécrite à
 *   chaque requête grossissait de ~30 Ko par réponse postée (audit de #644,
 *   mesuré). Ce serveur n'envoie aucune requête au client (ni sampling, ni
 *   elicitation, ni roots) : aucune réponse n'est attendue. Le jour où un
 *   outil en sollicite une, ce refus doit apprendre à reconnaître la réponse
 *   attendue.
 *
 * Un corps illisible est laissé au SDK, qui le refuse lui-même (erreur
 * d'analyse). Le refus a la forme de ceux du transport du SDK : un 400
 * `application/json` portant une erreur JSON-RPC `-32600` sans identifiant. Il
 * précède le contrôle d'hôte du SDK : un hôte étranger reçoit ce 400 au lieu
 * du 403, sans rien de servi dans un cas comme dans l'autre.
 *
 * Seul un corps qui commence par `[` ou `{` est décodé : borné à 16 Kio par
 * nginx (location `= /api/mcp`), il l'est déjà quand il arrive ici.
 */
#[AsEventListener(event: RequestEvent::class, priority: 15)]
final readonly class InboundMessageRefusalListener
{
    private const string ENDPOINT = '/api/mcp';

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // Chemin décodé (CanonicalPath, issue #77) : `/api/%6Dcp` est routé
        // vers le serveur MCP, il doit être refusé de même.
        if (Request::METHOD_POST !== $request->getMethod() || self::ENDPOINT !== CanonicalPath::of($request)) {
            return;
        }

        $refusal = $this->refusalOf($request->getContent());
        if (null === $refusal) {
            return;
        }

        $event->setResponse(JsonResponse::fromJsonString(
            json_encode(Error::forInvalidRequest($refusal->message()), \JSON_THROW_ON_ERROR),
            Response::HTTP_BAD_REQUEST,
        ));
    }

    private function refusalOf(string $body): ?InboundMessageRefusal
    {
        // Les blancs JSON seulement (RFC 8259) : ceux que `json_decode` admet.
        $head = ltrim($body, " \t\n\r");
        if (!str_starts_with($head, '[') && !str_starts_with($head, '{')) {
            return null;
        }

        try {
            $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!\is_array($decoded)) {
            return null;
        }

        $messages = array_is_list($decoded) ? $decoded : [$decoded];
        if (\count($messages) > 1) {
            return InboundMessageRefusal::SeveralMessages;
        }

        foreach ($messages as $message) {
            if (!\is_array($message)) {
                continue;
            }
            if ($this->isResponse($message)) {
                return InboundMessageRefusal::ClientResponse;
            }
            if ($this->declaresTooMuch($message)) {
                return InboundMessageRefusal::OversizedDeclaration;
            }
        }

        return null;
    }

    /**
     * Le critère du SDK (`MessageFactory`) : sans `method`, avec `result` ou
     * `error`, un message est une réponse.
     *
     * @param array<mixed> $message
     */
    private function isResponse(array $message): bool
    {
        return !\array_key_exists('method', $message)
            && (\array_key_exists('result', $message) || \array_key_exists('error', $message));
    }

    /**
     * @param array<mixed> $message
     */
    private function declaresTooMuch(array $message): bool
    {
        if ('initialize' !== ($message['method'] ?? null) || !\is_array($message['params'] ?? null)) {
            return false;
        }

        $params = $message['params'];
        $clientInfo = \is_array($params['clientInfo'] ?? null) ? $params['clientInfo'] : [];
        $capabilities = \is_array($params['capabilities'] ?? null) ? $params['capabilities'] : [];

        return InboundMessageRefusal::declarationBytes($clientInfo, $capabilities) > InboundMessageRefusal::MAX_DECLARATION_BYTES;
    }
}
