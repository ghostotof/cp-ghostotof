<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\Http;

use App\Shared\Infrastructure\Http\CanonicalPath;
use JsonException;
use Mcp\Schema\JsonRpc\Error;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Refuse en 400 un lot JSON-RPC de plusieurs messages sur `/api/mcp`, avant
 * le SDK (#644).
 *
 * Le SDK 0.8.1 accepte jusqu'à 100 messages par requête HTTP, une borne codée
 * en dur que le bundle ne rend pas configurable
 * (`MessageFactory::DEFAULT_MAX_BATCH_SIZE`, construite par le `Builder`) :
 * 100 `tools/call` — autant d'appels de provider, de sérialisations et de
 * lignes `mcp_usage` — pour une seule requête comptée par la zone nginx `mcp`.
 * Les lots ont disparu du protocole à la révision 2025-06-18, la seule que le
 * serveur négocie en handshake : aucun client conforme n'en envoie.
 *
 * Un lot d'un seul message coûte un message : il passe. Un corps illisible
 * aussi, que le SDK refuse lui-même (erreur d'analyse). Le refus a la forme
 * de ceux du transport du SDK : un 400 `application/json` portant une erreur
 * JSON-RPC `-32600` sans identifiant (ADR 0006 ; la révision 2025-06-18 veut
 * un statut HTTP d'erreur pour une entrée que le serveur n'accepte pas).
 *
 * Le corps n'est décodé que s'il commence par un crochet : borné à 64 Kio par
 * nginx, il l'est déjà quand il arrive ici.
 */
#[AsEventListener(event: RequestEvent::class, priority: 15)]
final readonly class JsonRpcBatchRefusalListener
{
    private const string ENDPOINT = '/api/mcp';

    /** Message de l'erreur JSON-RPC rendue, lu par le client, donc au palier anonyme (ADR 0006 D8). */
    public const string REFUSAL = 'JSON-RPC batches are not supported: send one message per request.';

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // Chemin décodé (CanonicalPath, issue #77) : `/api/%6Dcp` est routé
        // vers le serveur MCP, il doit être refusé de même.
        if ('POST' !== $request->getMethod() || self::ENDPOINT !== CanonicalPath::of($request)) {
            return;
        }

        if (!$this->isBatchOfSeveralMessages($request->getContent())) {
            return;
        }

        $event->setResponse(new Response(
            json_encode(Error::forInvalidRequest(self::REFUSAL), \JSON_THROW_ON_ERROR),
            Response::HTTP_BAD_REQUEST,
            ['Content-Type' => 'application/json'],
        ));
    }

    private function isBatchOfSeveralMessages(string $body): bool
    {
        // Les blancs JSON seulement (RFC 8259) : ceux que `json_decode` admet.
        if (!str_starts_with(ltrim($body, " \t\n\r"), '[')) {
            return false;
        }

        try {
            $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        return \is_array($decoded) && array_is_list($decoded) && \count($decoded) > 1;
    }
}
