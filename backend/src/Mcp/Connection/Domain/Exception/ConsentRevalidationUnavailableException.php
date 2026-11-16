<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Exception;

/**
 * Refus passager de la revalidation au consentement (hôte ou réseau en défaut) : 503, comme au
 * point d'autorisation (#651). Voir {@see ConsentTemporarilyUnavailableException}.
 */
final class ConsentRevalidationUnavailableException extends ConsentTemporarilyUnavailableException
{
    protected function problemStatus(): int
    {
        return 503;
    }
}
