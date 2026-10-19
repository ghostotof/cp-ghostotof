<?php

declare(strict_types=1);

namespace App\Mcp\Presentation\Tool;

use App\Mcp\Application\RoutePayloadReaderInterface;
use App\Portfolio\About\Domain\Exception\AboutSettingsNotFoundException;
use App\Portfolio\About\Presentation\ApiResource\AboutContentResource;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;

/**
 * `get_about` : la page « À propos », exactement ce que sert
 * `GET /api/about/{locale}` (palier anonyme, ADR 0006 D1).
 *
 * La description et le schéma sont lus par n'importe quel client : ils ne
 * nomment pas l'auteur (D8).
 */
final readonly class GetAboutTool
{
    // Schéma fermé (`additionalProperties: false`) : un champ ajouté à la
    // route sans être déclaré ici fait échouer la validation du test, au
    // lieu d'échapper au schéma que lisent les clients.
    private const array CARD_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'title' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            // Absent quand la carte n'a pas d'icône (la route omet les null).
            'iconKey' => ['type' => 'string'],
        ],
        'required' => ['title', 'description'],
        'additionalProperties' => false,
    ];

    private const array CARDS_SCHEMA = ['type' => 'array', 'items' => self::CARD_SCHEMA];

    private const array OUTPUT_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'site' => [
                'type' => 'object',
                'description' => 'About the site itself.',
                'properties' => [
                    'eyebrow' => ['type' => 'string'],
                    'cards' => self::CARDS_SCHEMA,
                ],
                'required' => ['eyebrow', 'cards'],
                'additionalProperties' => false,
            ],
            'me' => [
                'type' => 'object',
                'description' => 'About the site\'s author: technical side, personal side, hobbies.',
                'properties' => [
                    'eyebrow' => ['type' => 'string'],
                    'technicalSubtitle' => ['type' => 'string'],
                    'technicalCards' => self::CARDS_SCHEMA,
                    'personalSubtitle' => ['type' => 'string'],
                    'personalCards' => self::CARDS_SCHEMA,
                    'hobbiesSubtitle' => ['type' => 'string'],
                    'hobbiesCards' => self::CARDS_SCHEMA,
                ],
                'required' => ['eyebrow', 'technicalSubtitle', 'technicalCards', 'personalSubtitle', 'personalCards', 'hobbiesSubtitle', 'hobbiesCards'],
                'additionalProperties' => false,
            ],
        ],
        'required' => ['site', 'me'],
        'additionalProperties' => false,
    ];

    public function __construct(
        private RoutePayloadReaderInterface $routePayloadReader,
    ) {
    }

    /**
     * @param Locale $locale language of the content: "fr" (default) or "en"
     */
    #[McpTool(
        name: 'get_about',
        title: 'About',
        description: 'The "About" page of the site: what the site is and how it is built, then the site\'s author from a technical, personal and hobbies angle. Returns exactly what the page shows.',
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        outputSchema: self::OUTPUT_SCHEMA,
    )]
    public function __invoke(Locale $locale = Locale::FR): CallToolResult
    {
        try {
            $payload = $this->routePayloadReader->read(AboutContentResource::class, ['locale' => $locale->value]);
        } catch (AboutSettingsNotFoundException) {
            // La route répond 404 ; le modèle doit lire pourquoi, pas une
            // erreur interne.
            throw new ToolCallException('No About content exists in this language.');
        }

        return RouteBackedToolResult::forObject($payload);
    }
}
