<?php

declare(strict_types=1);

namespace App\Ai\Translation\Domain\Exception;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Shared\Domain\Exception\HasProblemType;

/**
 * La traduction n'a pas pu être produite : fournisseur injoignable, délai
 * dépassé, réponse hors du schéma demandé. Le message est volontairement
 * générique — la cause (message du fournisseur, sortie brute) est journalisée
 * par l'appelant, jamais renvoyée au client. Mappée 503 (ADR 0004), avec un
 * `type` stable (`/errors/translation-unavailable`) sur lequel le frontend
 * s'appuie pour choisir son message.
 */
final class TranslationUnavailableException extends \RuntimeException implements ProblemExceptionInterface
{
    use HasProblemType;

    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct("L'assistant de traduction est indisponible. Réessayez plus tard.", 0, $previous);
    }

    protected function problemType(): string
    {
        return 'translation-unavailable';
    }

    protected function problemStatus(): int
    {
        return 503;
    }
}
