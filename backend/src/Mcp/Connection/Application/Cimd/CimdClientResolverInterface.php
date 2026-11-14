<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application\Cimd;

use App\Mcp\Connection\Domain\Exception\ClientMetadataRefusedException;
use App\Mcp\Connection\Domain\ValueObject\CimdClientId;
use App\Mcp\Connection\Domain\ValueObject\ClientMetadataDocument;

/**
 * Rend le document de métadonnées valide d'un `client_id` CIMD (#651) : celui
 * qu'une récupération récente a gardé, ou, à défaut, celui que l'hôte du
 * client sert maintenant.
 */
interface CimdClientResolverInterface
{
    /**
     * @throws ClientMetadataRefusedException quand aucun document valide n'a pu être obtenu
     */
    public function resolve(CimdClientId $clientId): ClientMetadataDocument;
}
