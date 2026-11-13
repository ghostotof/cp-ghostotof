<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application\Cimd;

use App\Mcp\Connection\Domain\Exception\ClientMetadataRefusedException;
use App\Mcp\Connection\Domain\ValueObject\CimdClientId;

/**
 * Va chercher le document de métadonnées que l'URL d'un `client_id` CIMD
 * désigne (#651). Le port derrière lequel vit toute la défense contre le
 * SSRF : l'appelant ne voit qu'un document ou un refus nommé.
 */
interface ClientMetadataFetcherInterface
{
    /**
     * @throws ClientMetadataRefusedException quand le document n'a pas été obtenu
     *                                        ou n'est pas admissible ; la cause dit si un nouvel essai peut réussir
     */
    public function fetch(CimdClientId $clientId): FetchedClientMetadata;
}
