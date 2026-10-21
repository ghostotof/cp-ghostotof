<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\Http;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\ResetInterface;
use WeakMap;

/**
 * Les réponses HTTP que le serveur MCP a rendues lui-même : celles du
 * contrôleur de `/api/mcp` ({@see \App\Mcp\Presentation\Controller\McpEndpointController})
 * et celles du refus avant le SDK ({@see InboundMessageRefusalListener}) — audit
 * de #675, C9.
 *
 * Le journal d'appels lit l'échange de la révision sans session sur la requête
 * et la réponse HTTP ({@see \App\Mcp\Infrastructure\Log\ModernExchange}) : un
 * corps que le classifieur du SDK range dans l'ère moderne, et une réponse JSON.
 * Un 404, un 405 ou un 400 du framework sous `/api` est aussi du JSON
 * (`ApiJsonErrorFormatListener`), qu'aucun code MCP n'a produit : sans cette
 * provenance, il recevait une ligne d'appel de la méthode envoyée.
 *
 * La réponse est reconnue **par son objet**, pas par un attribut de la
 * requête : une réponse qu'un écouteur remplace ensuite (une exception à
 * `kernel.response`, rendue par l'`ErrorListener`) n'est plus celle du serveur.
 * Une `WeakMap` ne retient aucune réponse au-delà de sa vie.
 */
final class ServerAnswers implements ServerAnswersInterface, ResetInterface
{
    /** @var WeakMap<Response, true> */
    private WeakMap $answers;

    public function __construct()
    {
        $this->answers = new WeakMap();
    }

    public function record(Response $response): Response
    {
        $this->answers[$response] = true;

        return $response;
    }

    public function recorded(Response $response): bool
    {
        return isset($this->answers[$response]);
    }

    public function reset(): void
    {
        $this->answers = new WeakMap();
    }
}
