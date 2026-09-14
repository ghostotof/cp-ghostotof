<?php

declare(strict_types=1);

namespace App\Ai\Translation\Domain\Exception;

/**
 * La traduction n'a pas pu être produite : fournisseur injoignable, délai
 * dépassé, réponse hors du schéma demandé. Le message est volontairement
 * générique — la cause (message du fournisseur, sortie brute) est journalisée
 * par l'appelant, jamais renvoyée au client. Mappée 503 (ADR 0004).
 */
final class TranslationUnavailableException extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct("L'assistant de traduction est indisponible. Réessayez plus tard.", 0, $previous);
    }
}
