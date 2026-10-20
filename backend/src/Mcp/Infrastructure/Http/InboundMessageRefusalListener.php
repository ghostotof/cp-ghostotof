<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\Http;

use App\Shared\Infrastructure\Http\CanonicalPath;
use JsonException;
use Mcp\Exception\MissingRequestMetaException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Server\Stateless\RequestMeta;
use Mcp\Server\Stateless\StatelessProtocol;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Throwable;

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
 * - **une réponse du client**, au sens du SDK — `error` ou `result`, lus
 *   avant `method` : une requête qui en porte un en est une (#671). Le SDK
 *   range chaque réponse en session sous l'identifiant que le client choisit
 *   (`Protocol::handleResponse()`), et elles s'accumulent jusqu'à la borne de
 *   session : la ligne réécrite à chaque requête grossissait de ~30 Ko par
 *   réponse postée (audit de #644, mesuré). Ce serveur n'envoie aucune requête au client (ni sampling, ni
 *   elicitation, ni roots) : aucune réponse n'est attendue. Le jour où un
 *   outil en sollicite une, ce refus doit apprendre à reconnaître la réponse
 *   attendue ;
 * - **un `_meta` qui n'est pas un objet** (#664). La fabrique du SDK range celui
 *   d'une notification dans une propriété typée tableau : un scalaire lui fait
 *   lever une `TypeError`, une ligne `error` du canal `mcp` à volonté ;
 * - **un `clientInfo` de la révision sans session que le SDK ne sait pas
 *   lire** (#664) — il le construit hors de tout `try` et répondait 500 ;
 * - **`subscriptions/listen`** (#675). Le SDK tient le flux 30 s (durée que
 *   le bundle ne laisse régler qu'avec un bus de notifications), un worker
 *   php-fpm pris pour toute la durée : quatorze `listen` d'une IP saturaient
 *   un pod. Ce serveur n'émet aucune notification et n'en annonce aucune
 *   ({@see \App\Mcp\Infrastructure\DependencyInjection\TruthfulCapabilitiesPass}) :
 *   un client conforme n'a rien à écouter.
 *
 * Un corps illisible est laissé au SDK, qui le refuse lui-même (erreur
 * d'analyse). Le refus a la forme de ceux du transport du SDK : un 400
 * `application/json` portant une erreur JSON-RPC `-32600` sans identifiant —
 * sauf `listen`, requête bien formée : `-32601` avec son identifiant
 * ({@see InboundMessageRefusal::error()}). Il
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

        $error = $this->refusalOf($request->getContent());
        if (null === $error) {
            return;
        }

        $event->setResponse(JsonResponse::fromJsonString(
            json_encode($error, \JSON_THROW_ON_ERROR),
            Response::HTTP_BAD_REQUEST,
        ));
    }

    private function refusalOf(string $body): ?Error
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
            return InboundMessageRefusal::SeveralMessages->error(null);
        }

        foreach ($messages as $message) {
            if (!\is_array($message)) {
                continue;
            }
            $refusal = $this->refusalOfMessage($message);
            if (null !== $refusal) {
                return $refusal->error($this->requestIdOf($message));
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $message
     */
    private function refusalOfMessage(array $message): ?InboundMessageRefusal
    {
        if ($this->isResponse($message)) {
            return InboundMessageRefusal::ClientResponse;
        }
        // Sur la méthode seule, quelle que soit l'ère : le classifieur du
        // SDK la choisit d'après le corps, en-tête de session ou non.
        if (StatelessProtocol::LISTEN_METHOD === ($message['method'] ?? null)) {
            return InboundMessageRefusal::SubscriptionListen;
        }
        $meta = \is_array($message['params'] ?? null) ? ($message['params']['_meta'] ?? null) : null;
        if (null !== $meta && !\is_array($meta)) {
            return InboundMessageRefusal::MalformedMeta;
        }
        if (\is_array($meta) && $this->declaresAnUnreadableClient($meta)) {
            return InboundMessageRefusal::MalformedClientInfo;
        }
        if ($this->declaresTooMuch($message)) {
            return InboundMessageRefusal::OversizedDeclaration;
        }

        return null;
    }

    /**
     * L'identifiant tel que le SDK le lit (`StatelessProtocol::handle()`) : une
     * chaîne ou un entier, sinon aucun — jamais un identifiant fabriqué.
     *
     * @param array<mixed> $message
     */
    private function requestIdOf(array $message): string|int|null
    {
        $id = $message['id'] ?? null;

        return \is_string($id) || \is_int($id) ? $id : null;
    }

    /**
     * Le critère du SDK 0.8.1, dans son ordre
     * ({@see \Mcp\JsonRpc\MessageFactory::createMessage()}) : `error`, puis
     * `result`, lus par `isset` **avant** `method`. Une requête portant un
     * résultat est donc une réponse pour lui, rangée en session — exiger
     * l'absence de `method` la laissait passer (#671). Une valeur `null` ne
     * compte pas : le SDK sert alors la requête. Une valeur fausse mais non
     * nulle (`false`, `0`, `""`) compte : le SDK la refuse ensuite comme
     * invalide, ce refus la précède, sans identifiant.
     * {@see \App\Tests\Mcp\Infrastructure\Http\InboundMessageClassificationTest}
     * lie ce critère à la `MessageFactory`.
     *
     * @param array<mixed> $message
     */
    private function isResponse(array $message): bool
    {
        return isset($message['error']) || isset($message['result']);
    }

    /**
     * L'enveloppe `_meta` de la révision sans session, lue par la méthode même du
     * SDK : `StatelessProtocol::handle()` ne rattrape que son refus d'une
     * enveloppe incomplète (un 400 qu'il rend lui-même), et le `clientInfo`
     * qu'il y construit lève autre chose sur un nom qui n'est pas du texte — il
     * répondait 500, une ligne `critical` à volonté (audit de #664).
     *
     * @param array<mixed> $meta
     */
    private function declaresAnUnreadableClient(array $meta): bool
    {
        try {
            RequestMeta::fromParams(['_meta' => $meta]);
        } catch (MissingRequestMetaException) {
            return false;
        } catch (Throwable) {
            // Ce que le SDK ne rattrape pas (une `Mcp\Exception\InvalidArgumentException`
            // aujourd'hui, que la méthode ne déclare pas).
            return true;
        }

        return false;
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
