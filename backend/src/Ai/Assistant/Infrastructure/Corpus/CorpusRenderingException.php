<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Infrastructure\Corpus;

use RuntimeException;

/**
 * Un contenu du corpus n'a pas pu être rendu : PCRE en échec (UTF-8 invalide,
 * limite de retour arrière épuisée), ou un provider qui ne rend pas une
 * collection du DTO attendu (issue #319). Le rendu s'arrête plutôt que
 * d'omettre le champ ou la section : un document manquant sans le dire ferait
 * répondre à l'assistant « ce n'est pas dans les documents ». Le message ne
 * cite jamais le contenu, seulement des noms de types.
 *
 * Infrastructure, pas domaine : c'est une panne du moteur d'expressions
 * régulières ou du câblage des sources, pas une règle métier. Elle sort en
 * 500 : un contenu que le rendu ne sait pas lire est un défaut à corriger, pas
 * une indisponibilité.
 */
final class CorpusRenderingException extends RuntimeException
{
}
