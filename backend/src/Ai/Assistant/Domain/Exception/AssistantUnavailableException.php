<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Domain\Exception;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Shared\Domain\Exception\HasProblemType;

/**
 * L'assistant n'a pas pu répondre : fournisseur injoignable, refus, délai
 * dépassé, flux interrompu. Le message est volontairement générique — la cause
 * (classe, statut HTTP du fournisseur) est journalisée par l'appelant, jamais
 * renvoyée au client. Mappée 503 avec un `type` stable
 * (`/errors/assistant-unavailable`) ; après le début du flux, elle devient
 * l'événement `error` de même raison.
 */
final class AssistantUnavailableException extends \RuntimeException implements ProblemExceptionInterface
{
    use HasProblemType;

    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct("L'assistant est indisponible. Réessayez plus tard.", 0, $previous);
    }

    protected function problemType(): string
    {
        return 'assistant-unavailable';
    }

    protected function problemStatus(): int
    {
        return 503;
    }
}
