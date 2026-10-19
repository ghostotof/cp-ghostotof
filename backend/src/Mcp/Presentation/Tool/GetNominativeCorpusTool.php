<?php

declare(strict_types=1);

namespace App\Mcp\Presentation\Tool;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * `get_nominative_corpus` : le corpus nominatif, réservé au palier de
 * confiance par une connexion MCP (ADR 0006 D3).
 *
 * **Toujours listé**, et sa description dit qu'il exige une connexion MCP :
 * c'est ce sur quoi s'appuiera en phase 2 l'authentification différée de
 * Claude (401 sur ce `tools/call`) et de ChatGPT. En phase 1 aucune connexion
 * n'existe : l'appel rend une erreur d'outil, sans rien lire — l'outil n'a
 * aucune dépendance, il ne peut rien laisser passer du corpus.
 *
 * L'un des deux outils sans route adossée, dans la liste fermée du test
 * d'exposition symétrique (ADR 0006 D1) : ses sources sont celles que
 * `CorpusSourcesTest` fixe. La description ne nomme pas l'auteur (D8).
 */
final readonly class GetNominativeCorpusTool
{
    #[McpTool(
        name: 'get_nominative_corpus',
        title: 'Nominative CV',
        description: 'The full CV of the site\'s author: identity, employers and career history. Requires an MCP connection, granted to a person of the site\'s trusted tier; without one, the call fails and returns nothing of the CV.',
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    )]
    public function __invoke(
        #[Schema(description: ToolLocale::DESCRIPTION)]
        string $locale = ToolLocale::DEFAULT,
    ): never {
        // La langue est lue d'abord, comme sur les autres outils : une valeur
        // inconnue reste l'erreur que le modèle sait corriger.
        ToolLocale::parse($locale);

        throw new ToolCallException('An MCP connection is required to read the nominative corpus, and this server does not offer one yet.');
    }
}
