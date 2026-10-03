<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Application\Corpus;

/**
 * Texte du CV nominatif, la troisième source du corpus (spec 0005 D7) : le
 * même fichier que celui servi par GET /api/cv au palier ROLE_TRUSTED.
 *
 * Extrait à chaque appel, jamais mis en cache : `cache.app` est en base depuis
 * l'ADR 0005, y ranger ce texte écrirait le CV nominatif dans `cache_items`.
 */
interface PdfTextExtractorInterface
{
    /**
     * @return string|null le texte normalisé, paragraphes séparés par une ligne
     *                     vide ; null si le fichier est absent, ce qui n'est
     *                     pas une erreur
     */
    public function extract(): ?string;
}
