<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Exception;

/**
 * Refus passager de la revalidation au consentement (quota de récupération épuisé) : 429, comme au
 * point d'autorisation (#651). Voir {@see ConsentTemporarilyUnavailableException}.
 */
final class ConsentRevalidationThrottledException extends ConsentTemporarilyUnavailableException
{
    protected function problemStatus(): int
    {
        return 429;
    }
}
