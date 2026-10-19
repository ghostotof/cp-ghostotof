<?php

declare(strict_types=1);

namespace App\Mcp\Presentation\Tool;

use App\Mcp\Application\RoutePayloadReaderInterface;
use App\Portfolio\CaseStudy\Presentation\ApiResource\CaseStudyResource;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;

/**
 * `list_case_studies` : les études de cas techniques, exactement ce que sert
 * `GET /api/case-studies/{locale}` (palier de base, ADR 0006 D1), enveloppé
 * dans `{"items": …}`.
 *
 * Servi sans jeton (ADR 0006 D2, qui amende l'ADR 0003 D1) : le palier de base
 * est publiable par construction — ni nom, ni employeur, ni client (ADR 0003
 * D5). La description et le schéma ne nomment pas l'auteur (D8).
 */
final readonly class ListCaseStudiesTool
{
    // Schéma fermé : un champ ajouté à la route sans être déclaré ici fait
    // échouer la validation du test.
    private const array OUTPUT_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'items' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'problem' => ['type' => 'string'],
                        'solution' => ['type' => 'string'],
                        'tradeoffs' => ['type' => 'string'],
                        'measuredResult' => ['type' => 'string'],
                    ],
                    'required' => ['title', 'problem', 'solution', 'tradeoffs', 'measuredResult'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => ['items'],
        'additionalProperties' => false,
    ];

    public function __construct(
        private RoutePayloadReaderInterface $routePayloadReader,
    ) {
    }

    #[McpTool(
        name: 'list_case_studies',
        title: 'Case studies',
        description: 'Technical case studies of the site\'s author: the problem, the solution, its trade-offs and the measured result, with no name, employer nor client. Returns exactly what the "Case studies" page shows to base access.',
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        outputSchema: self::OUTPUT_SCHEMA,
    )]
    public function __invoke(
        #[Schema(description: ToolLocale::DESCRIPTION)]
        string $locale = ToolLocale::DEFAULT,
    ): CallToolResult {
        $language = ToolLocale::parse($locale);

        return RouteBackedToolResult::forCollection(
            $this->routePayloadReader->read(CaseStudyResource::class, ['locale' => $language->value]),
        );
    }
}
