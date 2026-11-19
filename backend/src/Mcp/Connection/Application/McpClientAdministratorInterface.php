<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Exception\InvalidMcpClientRedirectsException;
use App\Mcp\Connection\Domain\Exception\McpClientAlreadyRegisteredException;
use App\Mcp\Connection\Domain\Exception\McpClientDeclaredByDocumentException;
use App\Mcp\Connection\Domain\Exception\McpClientNotFoundException;
use App\Mcp\Connection\Domain\ValueObject\McpClientId;
use App\Mcp\Connection\Domain\ValueObject\McpClientName;
use App\Mcp\Connection\Domain\ValueObject\RedirectUri;
use Symfony\Component\Uid\Uuid;

/**
 * L'administration des clients MCP depuis le backoffice (#652) : enregistrer,
 * réviser, supprimer. Ce qu'un contrôleur appelle ; la commande
 * `app:mcp:client:register` garde {@see McpClientRegistrarInterface}, avec
 * l'origine `Console`.
 */
interface McpClientAdministratorInterface
{
    /**
     * Enregistre un client pré-enregistré ; l'acteur du journal est celui de la
     * requête (`AuditOrigin::Request`).
     *
     * @param non-empty-list<RedirectUri> $redirectUris
     *
     * @throws McpClientAlreadyRegisteredException  si l'identifiant public est déjà pris
     * @throws InvalidMcpClientRedirectsException   si les redirections distinctes sont hors de la borne de l'entité
     */
    public function register(McpClientId $clientId, McpClientName $name, array $redirectUris): McpClient;

    /**
     * Remplace le nom et les redirections d'un client pré-enregistré. **Aucune
     * connexion n'est coupée** : celles qui existent gardent leur accord, la
     * nouvelle liste ne joue qu'à la prochaine autorisation.
     *
     * @param non-empty-list<RedirectUri> $redirectUris
     *
     * @throws McpClientNotFoundException           le client n'existe pas
     * @throws McpClientDeclaredByDocumentException le client est déclaré par son document
     * @throws InvalidMcpClientRedirectsException   les redirections distinctes sont hors de la borne de l'entité
     */
    public function revise(Uuid $id, McpClientName $name, array $redirectUris): McpClient;

    /**
     * Supprime le client et coupe ses connexions actives.
     *
     * @return int le nombre de connexions que la suppression a coupées
     *
     * @throws McpClientNotFoundException le client n'existe pas
     */
    public function remove(Uuid $id): int;
}
