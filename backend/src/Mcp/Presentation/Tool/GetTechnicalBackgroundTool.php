<?php

declare(strict_types=1);

namespace App\Mcp\Presentation\Tool;

use App\Mcp\Application\RoutePayloadReaderInterface;
use App\Portfolio\Experience\Presentation\ApiResource\ExperienceTechnologyResource;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;

/**
 * `get_technical_background` : les technologies pratiquées et leur
 * ancienneté, exactement ce que sert `GET /api/experience/technologies`
 * (palier anonyme, ADR 0006 D1), enveloppé dans `{"items": …}`.
 *
 * Pas de paramètre de langue : la route n'en prend pas (un nom de technologie
 * et un nombre d'années ne se traduisent pas). La description et le schéma
 * sont lus par n'importe quel client : ils ne nomment pas l'auteur (D8).
 */
final readonly class GetTechnicalBackgroundTool
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
                        'name' => ['type' => 'string'],
                        'years' => ['type' => 'number', 'description' => 'Years of practice.'],
                        // Absent quand la technologie n'a pas d'icône (la
                        // route omet les null).
                        'iconKey' => ['type' => 'string'],
                        'relatedTechnology' => [
                            'type' => 'object',
                            'description' => 'The technology this one belongs to, when any (a framework\'s language).',
                            'properties' => ['name' => ['type' => 'string']],
                            'required' => ['name'],
                            'additionalProperties' => false,
                        ],
                        'secondary' => ['type' => 'boolean', 'description' => 'A technology practised alongside, without a duration shown on the site.'],
                    ],
                    'required' => ['name', 'years', 'secondary'],
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
        name: 'get_technical_background',
        title: 'Technical background',
        description: 'The technologies the site\'s author has practised, with the years of practice of each. Returns exactly what the "Experience" page shows.',
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        outputSchema: self::OUTPUT_SCHEMA,
    )]
    public function __invoke(): CallToolResult
    {
        return RouteBackedToolResult::forCollection(
            $this->routePayloadReader->read(ExperienceTechnologyResource::class),
        );
    }
}
