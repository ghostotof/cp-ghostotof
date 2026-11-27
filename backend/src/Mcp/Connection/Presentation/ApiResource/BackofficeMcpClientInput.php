<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Presentation\ApiResource;

use App\Mcp\Connection\Infrastructure\Validator\ValidMcpClientFields;

/**
 * Corps de POST /api/backoffice/mcp/clients : enregistre un client MCP
 * pré-enregistré (#652, spec 0007 T13). Aucune règle n'est recopiée ici :
 * `#[ValidMcpClientFields]` confie l'identifiant, le nom et chaque redirection
 * aux objets valeur du domaine (D-F), un 422 par champ fautif.
 *
 * Les défauts (chaîne vide, liste vide) sont volontaires, comme pour
 * {@see \App\Security\User\Presentation\ApiResource\BackofficeUserInviteInput} :
 * un champ absent échoue la validation (422 au chemin du champ) plutôt que de
 * lever une erreur de dénormalisation sans chemin.
 */
#[ValidMcpClientFields]
final readonly class BackofficeMcpClientInput
{
    /**
     * @param list<string> $redirectUris le validateur refuse une entrée qui n'est pas une chaîne
     */
    public function __construct(
        public string $clientId = '',
        public string $name = '',
        public array $redirectUris = [],
    ) {
    }
}
