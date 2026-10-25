<?php

declare(strict_types=1);

namespace App\Mcp\Presentation\Tool;

use App\Mcp\Application\RoutePayloadReaderInterface;
use App\Mcp\Application\ToolSecuritySchemes;
use App\Portfolio\Quality\Presentation\ApiResource\QualityContentResource;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;

/**
 * `get_quality` : les principes et traits de qualité, exactement ce que sert
 * `GET /api/quality/{locale}` (palier anonyme, ADR 0006 D1).
 *
 * La description et le schéma sont lus par n'importe quel client : ils ne
 * nomment pas l'auteur (D8).
 */
#[BackedByRoute(self::RESOURCE, AccessTier::ANONYMOUS)]
final readonly class GetQualityTool
{
    /** La ressource de la route servie, déclarée et lue par la même constante. */
    private const string RESOURCE = QualityContentResource::class;

    // Schéma fermé : un champ ajouté à la route sans être déclaré ici fait
    // échouer la validation du test.
    private const array OUTPUT_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'principles' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'iconKey' => ['type' => 'string'],
                    ],
                    'required' => ['title', 'description', 'iconKey'],
                    'additionalProperties' => false,
                ],
            ],
            'traits' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'label' => ['type' => 'string'],
                    ],
                    'required' => ['label'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => ['principles', 'traits'],
        'additionalProperties' => false,
    ];

    public function __construct(
        private RoutePayloadReaderInterface $routePayloadReader,
    ) {
    }

    #[McpTool(
        name: 'get_quality',
        title: 'Quality principles',
        description: 'The quality principles the site\'s author works by, and the traits that sum them up. Returns exactly what the home page shows.',
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        meta: [ToolSecuritySchemes::KEY => ToolSecuritySchemes::NO_AUTH],
        outputSchema: self::OUTPUT_SCHEMA,
    )]
    public function __invoke(
        #[Schema(description: ToolLocale::DESCRIPTION)]
        string $locale = ToolLocale::DEFAULT,
    ): CallToolResult {
        $language = ToolLocale::parse($locale);

        return JsonToolResult::forObject(
            $this->routePayloadReader->read(self::RESOURCE, ['locale' => $language->value]),
        );
    }
}
