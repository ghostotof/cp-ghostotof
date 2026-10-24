<?php

declare(strict_types=1);

namespace App\Mcp\Application;

/**
 * Les schémas de sécurité d'un outil MCP (#648, ADR 0006 D3), écrits une
 * fois : la clé que chaque outil déclare dans `#[McpTool(meta: …)]`, la source
 * unique, et la valeur commune à tous les outils publics.
 *
 * {@see \App\Mcp\Infrastructure\Registry\SecuritySchemesRegistry} relit la
 * déclaration sous cette clé et la republie au premier niveau de chaque
 * entrée de `tools/list`, que la référence de l'Apps SDK d'OpenAI lit
 * (`_meta` en est le miroir de rétrocompatibilité) : sans elle, ChatGPT
 * n'offre pas la liaison de compte.
 *
 * Dans l'Application plutôt qu'à côté du registre (Infrastructure) : les
 * outils (Presentation) et le registre la lisent tous deux, et aucun des dix
 * outils n'a à importer une classe d'Infrastructure pour la déclarer. Des
 * expressions constantes, pour être écrites dans l'attribut.
 */
final class ToolSecuritySchemes
{
    /** La clé, en `_meta` comme au premier niveau (référence de l'Apps SDK d'OpenAI). */
    public const string KEY = 'securitySchemes';

    /**
     * Appelable sans compte (ADR 0006 D3) : la déclaration de tout outil des
     * paliers anonyme et de base, `meta: [self::KEY => self::NO_AUTH]`.
     */
    public const array NO_AUTH = [['type' => 'noauth']];

    private function __construct()
    {
    }
}
