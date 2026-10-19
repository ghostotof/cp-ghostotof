<?php

declare(strict_types=1);

namespace App\Mcp\Presentation\Tool;

use App\Mcp\Application\RoutePayloadReaderInterface;
use App\Portfolio\AnonymousCv\Presentation\ApiResource\AnonymousCvSectionResource;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;

/**
 * `get_anonymous_cv` : le CV sans identité, exactement ce que sert
 * `GET /api/anonymous-cv/{locale}` (palier de base, ADR 0006 D1), enveloppé
 * dans `{"items": …}`.
 *
 * Servi sans jeton (ADR 0006 D2, qui amende l'ADR 0003 D1) : le palier de base
 * est publiable par construction — ni nom, ni employeur, ni client, ni
 * chronologie (ADR 0003 D5). La description et le schéma ne nomment pas
 * l'auteur (D8).
 */
final readonly class GetAnonymousCvTool
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
                        'title' => ['type' => 'string', 'description' => 'The domain of the section.'],
                        'skills' => ['type' => 'string'],
                        'yearsOfExperience' => ['type' => 'integer'],
                        'achievements' => ['type' => 'string'],
                    ],
                    'required' => ['title', 'skills', 'yearsOfExperience', 'achievements'],
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
        name: 'get_anonymous_cv',
        title: 'Anonymous CV',
        description: 'The CV of the site\'s author without identity: skills, years of experience and achievements per domain, with no name, employer, client nor career chronology. Returns exactly what the "Anonymous CV" page shows to base access.',
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        outputSchema: self::OUTPUT_SCHEMA,
    )]
    public function __invoke(
        #[Schema(description: ToolLocale::DESCRIPTION)]
        string $locale = ToolLocale::DEFAULT,
    ): CallToolResult {
        $language = ToolLocale::parse($locale);

        return RouteBackedToolResult::forCollection(
            $this->routePayloadReader->read(AnonymousCvSectionResource::class, ['locale' => $language->value]),
        );
    }
}
