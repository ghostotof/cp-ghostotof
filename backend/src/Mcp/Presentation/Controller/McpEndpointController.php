<?php

declare(strict_types=1);

namespace App\Mcp\Presentation\Controller;

use Symfony\AI\McpBundle\Controller\McpController;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Point d'entrée de `/api/mcp` : répond lui-même au GET, délègue le reste au
 * contrôleur du bundle.
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
 */
#[AsDecorator('mcp.server.portfolio.controller')]
final readonly class McpEndpointController
{
    /** Délai de reconnexion demandé au client, en millisecondes. */
    public const int RECONNECT_DELAY_MS = 300_000;

    public function __construct(
        #[AutowireDecorated]
        private McpController $inner,
    ) {
    }

    public function handle(Request $request): Response
    {
        if (Request::METHOD_GET !== $request->getMethod()) {
            return $this->inner->handle($request);
        }

        return new Response(
            \sprintf("retry: %d\n\n", self::RECONNECT_DELAY_MS),
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-store',
            ],
        );
    }
}
