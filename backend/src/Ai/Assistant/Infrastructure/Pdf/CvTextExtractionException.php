<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Infrastructure\Pdf;

/**
 * Le texte extrait du CV nominatif n'a pas pu être normalisé (PCRE en échec,
 * typiquement un UTF-8 invalide). Levée par ExtractedTextNormalizer plutôt que
 * de rendre un texte vidé en silence, et rattrapée par PopplerPdfTextExtractor,
 * qui déclare alors la section indisponible et le journalise (mode dégradé) :
 * elle ne sort jamais en 500.
 *
 * Le message est toujours littéral et ne cite jamais le texte (D10).
 */
final class CvTextExtractionException extends \RuntimeException
{
}
