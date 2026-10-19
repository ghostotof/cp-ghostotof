<?php

declare(strict_types=1);

namespace App\Mcp\Application;

/**
 * Lit ce que sert une route publique, sans passer par HTTP (ADR 0006 D1).
 *
 * Le serveur MCP ne sert rien qu'une route du même palier ne sert déjà : un
 * outil n'assemble donc pas sa réponse lui-même, il demande ici le payload de
 * la route qu'il expose. Un seul lecteur pour tous les outils, pour que la
 * règle « exactement la sortie de la route » soit écrite une fois.
 */
interface RoutePayloadReaderInterface
{
    /**
     * Rend le corps JSON décodé de l'unique opération GET publique de la
     * ressource, tel que la route le servirait.
     *
     * @param class-string          $resourceClass ressource API Platform de la route
     * @param array<string, string> $uriVariables  variables de son gabarit d'URI (`locale`…)
     *
     * @return array<mixed>
     */
    public function read(string $resourceClass, array $uriVariables = []): array;
}
