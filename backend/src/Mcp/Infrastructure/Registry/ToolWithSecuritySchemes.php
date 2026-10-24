<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\Registry;

use Mcp\Schema\Icon;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use stdClass;

/**
 * Un outil tel que `tools/list` le publie : celui du SDK, recopié champ pour
 * champ, plus `securitySchemes` au premier niveau (#648, ADR 0006 D3).
 *
 * `Mcp\Schema\Tool` 0.8.1 ne connaît pas ce champ, que la référence de l'Apps
 * SDK d'OpenAI lit au premier niveau de l'outil (elle tient
 * `_meta["securitySchemes"]` pour un miroir de rétrocompatibilité) : sans lui,
 * ChatGPT n'offre pas la liaison de compte. La valeur est **lue** dans le
 * `_meta["securitySchemes"]` de l'outil, que l'attribut `#[McpTool(meta: …)]`
 * déclare — une seule source —, jamais déduite ni complétée : un outil qui
 * n'en déclare pas lève {@see ToolWithoutSecuritySchemesException}.
 *
 * Une sous-classe plutôt qu'un objet `JsonSerializable` à part : la page de
 * `tools/list` (`Mcp\Schema\Page`) et son résultat sont typés `Tool`, et ce
 * que lisent les autres lecteurs du registre (le journal des appels, les
 * tests de catalogue) reste un `Tool`. La recopie passe chaque paramètre du
 * constructeur du SDK ; `SecuritySchemesRegistryTest` rougit si une montée en
 * ajoute un, que la recopie perdrait en silence.
 *
 * @phpstan-import-type ToolInputSchema from Tool
 * @phpstan-import-type ToolOutputSchema from Tool
 */
final class ToolWithSecuritySchemes extends Tool
{
    /** La clé, en `_meta` comme au premier niveau (référence de l'Apps SDK d'OpenAI). */
    public const string KEY = 'securitySchemes';

    /**
     * @param list<array<string, mixed>> $securitySchemes
     */
    private function __construct(Tool $tool, private readonly array $securitySchemes)
    {
        // Les schémas sont déjà normalisés (`{}` des sous-schémas vides) :
        // la normalisation du constructeur, rejouée, n'y change rien.
        parent::__construct(
            name: $tool->name,
            title: $tool->title,
            inputSchema: $tool->inputSchema,
            description: $tool->description,
            annotations: $tool->annotations,
            icons: $tool->icons,
            meta: $tool->meta,
            outputSchema: $tool->outputSchema,
        );
    }

    /**
     * @throws ToolWithoutSecuritySchemesException quand le `_meta` de l'outil ne
     *                                             porte pas une liste non vide
     *                                             de schémas typés
     */
    public static function of(Tool $tool): self
    {
        $schemes = $tool->meta[self::KEY] ?? null;
        if (!\is_array($schemes) || [] === $schemes || !array_is_list($schemes)) {
            throw ToolWithoutSecuritySchemesException::forTool($tool->name);
        }

        $typed = [];
        foreach ($schemes as $scheme) {
            // Un schéma est un objet qui dit son type (`noauth`, `oauth2`) ;
            // le reste (les scopes) est publié tel que l'attribut l'écrit.
            if (!\is_array($scheme) || !\is_string($scheme['type'] ?? null)) {
                throw ToolWithoutSecuritySchemesException::forTool($tool->name);
            }
            /** @var array<string, mixed> $scheme */
            $typed[] = $scheme;
        }

        return new self($tool, $typed);
    }

    /**
     * La sortie du SDK, plus `securitySchemes` en dernier.
     *
     * @return array{
     *     name: string,
     *     title?: string,
     *     inputSchema: ToolInputSchema,
     *     description?: string,
     *     annotations?: ToolAnnotations,
     *     icons?: Icon[],
     *     _meta?: array<string, mixed>,
     *     outputSchema?: ToolOutputSchema|stdClass,
     *     securitySchemes: list<array<string, mixed>>
     * }
     */
    public function jsonSerialize(): array
    {
        return parent::jsonSerialize() + [self::KEY => $this->securitySchemes];
    }
}
