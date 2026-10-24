<?php

declare(strict_types=1);

namespace App\Mcp\Presentation\Tool;

use App\Mcp\Application\RoutePayloadReaderInterface;
use App\Mcp\Application\ToolSecuritySchemes;
use App\Portfolio\Contribution\Presentation\ApiResource\ContributionResource;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;

/**
 * `list_contributions` : les contributions techniques publiques, exactement ce
 * que sert `GET /api/contributions/{locale}` (palier anonyme, ADR 0006 D1),
 * enveloppé dans `{"items": …}`.
 *
 * La description et le schéma sont lus par n'importe quel client : ils ne
 * nomment pas l'auteur (D8).
 */
#[BackedByRoute(self::RESOURCE, AccessTier::ANONYMOUS)]
final readonly class ListContributionsTool
{
    /** La ressource de la route servie, déclarée et lue par la même constante. */
    private const string RESOURCE = ContributionResource::class;

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
                        'project' => ['type' => 'string', 'description' => 'The project contributed to.'],
                        'reference' => ['type' => 'string', 'description' => 'Reference of the contribution in that project (pull request, issue…).'],
                        'url' => ['type' => 'string', 'description' => 'Public link to the contribution.'],
                        'summary' => ['type' => 'string'],
                        'body' => ['type' => 'string', 'description' => 'The argument of the contribution, plain text, paragraphs separated by a blank line.'],
                    ],
                    'required' => ['title', 'project', 'reference', 'url', 'summary', 'body'],
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
        name: 'list_contributions',
        title: 'Contributions',
        description: 'Technical contributions of the site\'s author to public projects, each with its link and its argument. Returns exactly what the "Contributions" page shows.',
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        meta: [ToolSecuritySchemes::KEY => ToolSecuritySchemes::NO_AUTH],
        outputSchema: self::OUTPUT_SCHEMA,
    )]
    public function __invoke(
        #[Schema(description: ToolLocale::DESCRIPTION)]
        string $locale = ToolLocale::DEFAULT,
    ): CallToolResult {
        $language = ToolLocale::parse($locale);

        return JsonToolResult::forCollection(
            $this->routePayloadReader->read(self::RESOURCE, ['locale' => $language->value]),
        );
    }
}
