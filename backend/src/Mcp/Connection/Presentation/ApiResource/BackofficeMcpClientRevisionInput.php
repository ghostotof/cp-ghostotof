<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Presentation\ApiResource;

use App\Mcp\Connection\Infrastructure\Validator\ValidMcpClientFields;

/**
 * Corps de PUT /api/backoffice/mcp/clients/{id} : remplace le nom et les
 * redirections d'un client MCP pré-enregistré (#652, spec 0007 T13, D-E).
 *
 * Pas de `clientId` : l'identifiant public d'un client ne se révise pas (il
 * est celui que les connexions et les consentements ont déjà vu). Les règles
 * ne sont pas recopiées ici : `#[ValidMcpClientFields]` confie le nom et
 * chaque redirection aux objets valeur du domaine (D-F), comme pour
 * {@see BackofficeMcpClientInput}, un 422 par champ fautif.
 *
 * Les défauts (chaîne vide, liste vide) sont volontaires : un champ absent
 * échoue la validation (422 au chemin du champ) plutôt que de lever une
 * erreur de dénormalisation sans chemin.
 */
#[ValidMcpClientFields]
final readonly class BackofficeMcpClientRevisionInput
{
    /**
     * @param list<string> $redirectUris le validateur refuse une entrée qui n'est pas une chaîne
     */
    public function __construct(
        public string $name = '',
        public array $redirectUris = [],
    ) {
    }
}
