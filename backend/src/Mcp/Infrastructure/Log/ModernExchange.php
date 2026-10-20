<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\Log;

use JsonException;
use Mcp\JsonRpc\MessageFactory;
use Mcp\Schema\JsonRpc\Notification;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Server\Stateless\RequestMeta;
use Mcp\Server\Stateless\StatelessProtocol;
use Mcp\Server\Wire\InboundClassifier;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un échange de la révision sans session (2026-07-28, SEP-2575), lu de la
 * requête et de la réponse HTTP (#664) : le SDK 0.8.1 le sert par
 * `StatelessProtocol`, une classe `final` construite dans `Builder::build()`,
 * qui n'émet aucun des événements que {@see McpCallLog} écoute pour l'autre ère.
 *
 * **L'ère est celle que le SDK décide** : son propre `InboundClassifier`, sur le
 * corps et les en-têtes, pas l'en-tête `MCP-Protocol-Version` seul (une révision
 * inconnue annoncée dans `_meta` est servie par l'ère moderne, qui la refuse ; un
 * en-tête moderne sans enveloppe est refusé avant toute ère). Un message que le
 * classifieur refuse n'est d'aucune ère : il reste à la ligne de repli.
 *
 * **La méthode est celle que le SDK reconnaît** : son `MessageFactory` en fait
 * une requête ou une notification typée, dont la classe donne le nom ; les deux
 * méthodes que le protocole sans état sert avant elle (`server/discover`,
 * `subscriptions/listen`) sont nommées par ses constantes. Toute autre, choisie
 * par l'appelant, sans borne, n'est pas recopiée — comme une méthode connue dont
 * le message est mal formé, ce que fait aussi l'autre ère.
 *
 * **L'issue est lue dans la réponse JSON** : un `error` (son `code`), un
 * `result.isError` à `true` (une erreur d'outil), sinon un succès. Une réponse
 * sans corps lisible — une notification acquittée, un flux SSE (`listen`, un
 * outil qui notifierait : aucun aujourd'hui) — suit son statut HTTP seul.
 */
final readonly class ModernExchange
{
    /**
     * @param array<mixed>|null $declaredClientInfo le `clientInfo` du `_meta`, tel que reçu
     */
    private function __construct(
        public ?string $method,
        public ?Request $request,
        public McpCallOutcome $outcome,
        public ?int $errorCode,
        public ?array $declaredClientInfo,
    ) {
    }

    /** L'échange, ou `null` quand le SDK n'a pas servi la requête par l'ère moderne. */
    public static function tryRead(HttpRequest $httpRequest, Response $response): ?self
    {
        if (HttpRequest::METHOD_POST !== $httpRequest->getMethod()) {
            return null;
        }

        $body = $httpRequest->getContent();
        $headers = array_map(static fn (array $values): string => implode(', ', $values), $httpRequest->headers->all());
        if (!(new InboundClassifier())->classify(HttpRequest::METHOD_POST, $body, $headers)->modern) {
            return null;
        }

        // Le classifieur n'a rangé dans l'ère moderne qu'un objet JSON lisible.
        $decoded = self::decodedObject($body) ?? [];
        $message = MessageFactory::make()->create($body)[0] ?? null;
        [$outcome, $errorCode] = self::outcomeOf($response);

        return new self(
            self::methodOf($message, $decoded['method'] ?? null),
            $message instanceof Request ? $message : null,
            $outcome,
            $errorCode,
            self::declaredClientInfoOf($decoded),
        );
    }

    private static function methodOf(mixed $message, mixed $sentMethod): ?string
    {
        return match (true) {
            $message instanceof Request, $message instanceof Notification => $message::getMethod(),
            \in_array($sentMethod, [StatelessProtocol::DISCOVER_METHOD, StatelessProtocol::LISTEN_METHOD], true) => $sentMethod,
            default => null,
        };
    }

    /**
     * @return array{McpCallOutcome, ?int}
     */
    private static function outcomeOf(Response $response): array
    {
        // Un flux SSE n'a pas de contenu lisible ici (`getContent()` rend `false`).
        $answer = self::decodedObject((string) $response->getContent());
        $error = $answer['error'] ?? null;
        $result = $answer['result'] ?? null;

        return match (true) {
            \is_array($error) => [McpCallOutcome::ProtocolError, \is_int($error['code'] ?? null) ? $error['code'] : null],
            \is_array($result) && true === ($result['isError'] ?? null) => [McpCallOutcome::ToolError, null],
            $response->getStatusCode() >= Response::HTTP_BAD_REQUEST => [McpCallOutcome::ProtocolError, null],
            default => [McpCallOutcome::Success, null],
        };
    }

    /**
     * @param array<mixed> $decoded
     *
     * @return array<mixed>|null
     */
    private static function declaredClientInfoOf(array $decoded): ?array
    {
        $params = $decoded['params'] ?? null;
        $meta = \is_array($params) ? ($params['_meta'] ?? null) : null;
        $clientInfo = \is_array($meta) ? ($meta[RequestMeta::CLIENT_INFO] ?? null) : null;

        return \is_array($clientInfo) ? $clientInfo : null;
    }

    /**
     * @return array<mixed>|null
     */
    private static function decodedObject(string $json): ?array
    {
        try {
            $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return \is_array($decoded) ? $decoded : null;
    }
}
