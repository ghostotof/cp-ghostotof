<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Infrastructure\Pdf;

/**
 * Le CV nominatif existe mais son texte n'a pas pu être lu (PDF corrompu,
 * pdftotext absent ou trop lent, UTF-8 invalide). Le corpus s'arrête plutôt
 * que d'omettre la section : un CV manquant sans le dire ferait répondre à
 * l'assistant « ce n'est pas dans les documents ». Sort en 500, comme
 * CorpusRenderingException : c'est un défaut à corriger, pas une indisponibilité.
 *
 * Le message est toujours littéral et l'exception ne chaîne **jamais** celle
 * de la bibliothèque : ProcessFailedException recopie la sortie standard du
 * processus, donc le texte déjà extrait, et l'ErrorListener du noyau journalise
 * toute la chaîne des exceptions précédentes (D10).
 */
final class CvTextExtractionException extends \RuntimeException
{
}
