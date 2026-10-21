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
     * Rend le corps JSON décodé de l'unique opération GET de la ressource que
     * le serveur peut servir sans connexion MCP (paliers anonyme et de base),
     * tel que la route le servirait.
     *
     * Les exceptions de domaine du provider (une langue sans contenu, que la
     * route rend en 404) le traversent telles quelles : l'outil les convertit
     * en erreur d'outil lisible par le modèle.
     *
     * @param class-string          $resourceClass ressource API Platform de la route
     * @param array<string, string> $uriVariables  variables de son gabarit d'URI (`locale`…)
     *
     * @return array<mixed>
     */
    public function read(string $resourceClass, array $uriVariables = []): array;
}
