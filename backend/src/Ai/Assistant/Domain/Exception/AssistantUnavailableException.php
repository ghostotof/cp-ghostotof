<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Domain\Exception;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Shared\Domain\Exception\HasProblemType;
use RuntimeException;

/**
 * L'assistant n'a pas pu répondre : fournisseur injoignable, refus, délai
 * dépassé, flux interrompu. Le message est volontairement générique — la cause
 * (classe, statut HTTP du fournisseur) est journalisée par l'appelant, jamais
 * renvoyée au client. Elle n'est pas non plus chaînée en `previous` :
 * l'ErrorListener du noyau journalise toute la chaîne, et le bridge recopie le
 * corps de la réponse du fournisseur dans le message de son exception (D10). Mappée 503 avec un `type` stable
 * (`/errors/assistant-unavailable`) ; après le début du flux, elle devient
 * l'événement `error` de même raison.
 */
final class AssistantUnavailableException extends RuntimeException implements ProblemExceptionInterface
{
    use HasProblemType;

    public function __construct()
    {
        parent::__construct("L'assistant est indisponible. Réessayez plus tard.");
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
