<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Exception\InvalidMcpClientRedirectsException;
use App\Mcp\Connection\Domain\Exception\McpClientAlreadyRegisteredException;
use App\Mcp\Connection\Domain\Exception\McpClientIdOfVerifiedDomainException;
use App\Mcp\Connection\Domain\ValueObject\McpClientId;
use App\Mcp\Connection\Domain\ValueObject\McpClientName;
use App\Mcp\Connection\Domain\ValueObject\RedirectUri;
use App\Security\Authentication\Application\AuditOrigin;

/**
 * Pré-enregistre un client MCP (#647) : il n'existe aucun enregistrement
 * dynamique (aucun `registration_endpoint`), un client n'est connu que par
 * cette voie.
 */
interface McpClientRegistrarInterface
{
    /**
     * L'identifiant, le nom et les redirections arrivent déjà validés par
     * leurs Value Objects : le type l'impose, aucune chaîne brute n'atteint
     * McpClient::register() par ici.
     *
     * L'enregistrement est audité (`mcp-client-registered`) **après** l'écriture :
     * un refus n'écrit rien et n'audite rien. `$origin` dit d'où il vient (la
     * commande déclare `Console`), pour que le journal nomme l'acteur.
     *
     * @param non-empty-list<RedirectUri> $redirectUris
     *
     * @throws McpClientIdOfVerifiedDomainException si l'identifiant est l'URL de métadonnées d'un domaine vérifié (CIMD) : refusé avant toute lecture et toute écriture
     * @throws McpClientAlreadyRegisteredException  si l'identifiant public est déjà pris
     * @throws InvalidMcpClientRedirectsException   si les redirections distinctes sont hors de la borne de l'entité (une à dix)
     */
    public function register(McpClientId $clientId, McpClientName $name, array $redirectUris, AuditOrigin $origin): McpClient;
}
