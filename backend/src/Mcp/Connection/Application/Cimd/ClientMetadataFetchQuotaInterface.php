<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application\Cimd;

use App\Mcp\Connection\Domain\Exception\ClientMetadataFetchRateLimitExceededException;

/**
 * Le plafond des récupérations de documents CIMD (#651, D-F) : une unité par
 * récupération réelle (un cache qui répond n'en coûte aucune), comptée par
 * adresse IP de l'appelant, jamais par `client_id` (un attaquant en changerait
 * à chaque essai).
 */
interface ClientMetadataFetchQuotaInterface
{
    /**
     * Consomme une unité du quota de l'appelant courant.
     *
     * @throws ClientMetadataFetchRateLimitExceededException quand le quota est épuisé
     */
    public function consume(): void;
}
