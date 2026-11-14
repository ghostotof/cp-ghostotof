<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Repository;

use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\ValueObject\McpClientId;

/**
 * Les clients MCP enregistrés. L'implémentation vit dans
 * Infrastructure\Doctrine\McpClientRepository.
 */
interface McpClientRepositoryInterface
{
    /** Par l'identifiant public du client (`client_id` d'OAuth), jamais par sa clé UUID. */
    public function findOneByClientId(string $clientId): ?McpClient;

    /**
     * Enregistre un nouveau client. Un identifiant public déjà pris est refusé
     * par la base, sous la contrainte McpClient::CLIENT_ID_UNIQUE_INDEX.
     */
    public function add(McpClient $client): void;

    /**
     * Dépose un client déclaré par son document (#651) : le crée, ou, s'il est
     * déjà déclaré (mode `cimd`), rafraîchit son nom et ses redirections, en
     * gardant son identifiant et sa date d'enregistrement (les connexions y
     * sont attachées). Rend le client tel que la base le porte.
     *
     * Rend `null` quand l'identifiant désigne un client **pré-enregistré** :
     * saisi par une personne, il n'est jamais réécrit par un document distant.
     * La ligne n'est pas touchée dans ce cas.
     *
     * Une seule requête atomique (`INSERT … ON CONFLICT DO UPDATE … WHERE`) :
     * deux autorisations simultanées du même client ne se disputent pas la
     * clé d'unicité, et la ligne reste verrouillée jusqu'à la fin de la
     * transaction de l'appelant. **Ordre des verrous** : l'appelant (la
     * complétion d'une autorisation) la lance avant toute écriture dans
     * `mcp_connection`, jamais après — `mcp_client` avant `mcp_connection`, comme
     * partout (voir `.claude/rules/mcp-oauth-purge.md`).
     *
     * L'argument n'est qu'un porteur de valeurs : il n'est jamais persisté
     * lui-même, le client rendu est l'entité gérée.
     */
    public function saveDiscovered(McpClient $discovered): ?McpClient;

    /**
     * Vrai si l'identifiant désigne un client déclaré par document (mode
     * `cimd`). Un client pré-enregistré, ou absent, n'en est pas un : c'est ce
     * qui choisit la voie du quota de récupération
     * (ClientMetadataFetchQuotaInterface::consumeForKnownClient()).
     */
    public function isDiscovered(McpClientId $clientId): bool;
}
