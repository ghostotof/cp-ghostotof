<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\ValueObject;

/**
 * Comment un client MCP est entré dans `mcp_client` (#651), écrit une fois à
 * l'enregistrement dans `mcp_client.registration` ; la base refuse toute autre
 * valeur (`chk_mcp_client_registration`).
 *
 * Le mode dit qui juge le nom et les redirections du client, donc qui peut les
 * réécrire :
 *  - `preregistered` : une personne (la commande `app:mcp:client:register`, le
 *    futur backoffice) les a saisis. Aucun document distant ne les réécrit
 *    jamais ;
 *  - `cimd` : le document de métadonnées du client les porte, jugé à chaque
 *    autorisation. Le dépôt peut les rafraîchir depuis un document plus récent
 *    ({@see \App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface::saveDiscovered()}),
 *    et la voie des clients connus du quota de récupération ne s'applique qu'à
 *    eux.
 *
 * Un mot stable, écrit en clair dans la migration (une migration est figée) :
 * une valeur de plus se fait par une nouvelle migration qui élargit la contrainte.
 */
enum ClientRegistration: string
{
    case Preregistered = 'preregistered';

    case Cimd = 'cimd';
}
