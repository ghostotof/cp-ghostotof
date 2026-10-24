<?php

declare(strict_types=1);

namespace App\Mcp\Presentation\Tool;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * `get_contact_info` : l'adresse de la page publique du formulaire de contact,
 * `<site>/<locale>/contact`, et rien d'autre (spec 0007) — ni adresse e-mail,
 * ni nom.
 *
 * L'un des deux outils sans route adossée, dans la liste fermée du test
 * d'exposition symétrique (ADR 0006 D1) : la route `/api/contact` est une
 * écriture, et la page qu'elle sert est publique. L'URL vient de
 * `APP_FRONTEND_BASE_URL`, la variable dont `allowed_hosts` tire l'hôte du
 * site : une seule source par environnement.
 */
final readonly class GetContactInfoTool
{
    private const array OUTPUT_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'contactPageUrl' => ['type' => 'string', 'description' => 'Public page of the site\'s contact form.'],
        ],
        'required' => ['contactPageUrl'],
        'additionalProperties' => false,
    ];

    public function __construct(
        #[Autowire(param: 'app.frontend_base_url')]
        private string $frontendBaseUrl,
    ) {
    }

    #[McpTool(
        name: 'get_contact_info',
        title: 'Contact',
        description: 'How to contact the site\'s author: the address of the site\'s contact form, to give to the user. No e-mail address is published.',
        annotations: new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
        // Appelable sans compte (ADR 0006 D3) ; SecuritySchemesRegistry le
        // publie aussi au premier niveau de tools/list.
        meta: ['securitySchemes' => [['type' => 'noauth']]],
        outputSchema: self::OUTPUT_SCHEMA,
    )]
    public function __invoke(
        #[Schema(description: ToolLocale::DESCRIPTION)]
        string $locale = ToolLocale::DEFAULT,
    ): CallToolResult {
        $language = ToolLocale::parse($locale);

        return JsonToolResult::forObject([
            'contactPageUrl' => \sprintf('%s/%s/contact', rtrim($this->frontendBaseUrl, '/'), $language->value),
        ]);
    }
}
