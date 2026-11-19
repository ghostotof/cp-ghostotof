<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application\Cimd;

use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Exception\ClientMetadataRefusedException;

/**
 * Décide, pour un `client_id` reçu à l'autorisation, si league le juge par les
 * lignes de la base ou par un client « déclaré » (CIMD, #651).
 */
interface CimdClientAdmissionInterface
{
    /**
     * Le nom de l'attribut de requête où l'admission dépose le client déclaré,
     * pour que le dépôt de clients de league le rende sans second appel.
     */
    public const string REQUEST_ATTRIBUTE = 'mcp.cimd.discovered_client';

    /**
     * @return McpClient|null `null` quand league juge le client par ses lignes
     *                        en base ; un client non persisté, en mode `cimd`,
     *                        quand league doit le juger par son document
     *
     * @throws ClientMetadataRefusedException quand le document n'est pas admis
     */
    public function admit(string $clientId): ?McpClient;
}
