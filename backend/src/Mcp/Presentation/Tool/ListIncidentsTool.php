<?php

declare(strict_types=1);

namespace App\Mcp\Presentation\Tool;

use App\Mcp\Application\RoutePayloadReaderInterface;
use App\Portfolio\Incident\Presentation\ApiResource\IncidentResource;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;

/**
 * `list_incidents` : le journal des pannes de production, exactement ce que
 * sert `GET /api/incidents/{locale}` (palier anonyme, ADR 0006 D1), enveloppé
 * dans `{"items": …}`.
 *
 * La description et le schéma sont lus par n'importe quel client : ils ne
 * nomment pas l'auteur (D8).
 */
#[BackedByRoute(self::RESOURCE, AccessTier::ANONYMOUS)]
final readonly class ListIncidentsTool
{
    /** La ressource de la route servie, déclarée et lue par la même constante. */
    private const string RESOURCE = IncidentResource::class;

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
                        'version' => ['type' => 'string', 'description' => 'Release of the site in which the outage occurred.'],
                        'occurredAt' => ['type' => 'string', 'description' => 'Day of the outage (YYYY-MM-DD).'],
                        'impact' => ['type' => 'string'],
                        'rootCause' => ['type' => 'string'],
                        'resolution' => ['type' => 'string'],
                        'invariant' => ['type' => 'string', 'description' => 'The rule drawn from the outage, enforced since.'],
                    ],
                    'required' => ['title', 'version', 'occurredAt', 'impact', 'rootCause', 'resolution', 'invariant'],
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
        name: 'list_incidents',
        title: 'Production incidents',
        description: 'Post-mortems of the site\'s production outages: impact, root cause, resolution and the invariant drawn from each. Returns exactly what the "Incidents" page shows.',
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
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
