<?php

declare(strict_types=1);

namespace App\Mcp\Presentation\Tool;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Mcp\Exception\ToolCallException;

/**
 * Le paramètre de langue des outils MCP, écrit une fois pour tous.
 *
 * Une chaîne dont la description cite les deux valeurs, pas une énumération
 * (décision du 2026-10-19, spec 0007) : avec une énumération, le SDK refusait
 * une autre valeur avant l'outil par un `-32602`, une erreur de protocole que
 * le modèle ne voit pas toujours. Ici la valeur atteint l'outil, qui répond
 * une erreur d'outil explicite que le modèle peut corriger — ce que la
 * révision MCP 2025-11-25 recommande pour une entrée invalide.
 */
final class ToolLocale
{
    /** Description du paramètre, à poser par `#[Schema(description: …)]`. */
    public const string DESCRIPTION = 'Language of the content: "fr" (French, default) or "en" (English).';

    /** Valeur par défaut du paramètre (littérale : un défaut de paramètre doit être constant). */
    public const string DEFAULT = 'fr';

    /**
     * @throws ToolCallException langue inconnue, renvoyée au modèle comme erreur d'outil
     */
    public static function parse(string $value): Locale
    {
        return Locale::tryFrom($value) ?? throw new ToolCallException('Unknown language: use "fr" or "en".');
    }
}
