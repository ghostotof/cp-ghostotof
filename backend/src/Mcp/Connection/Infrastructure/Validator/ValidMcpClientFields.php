<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\Validator;

use Attribute;
use Override;
use Symfony\Component\Validator\Constraint;

/**
 * Valide les champs d'un client MCP saisis à l'administration (#652) par les
 * règles du domaine, et non par une copie d'entre elles : l'identifiant
 * ({@see \App\Mcp\Connection\Domain\ValueObject\McpClientId}), le nom
 * ({@see \App\Mcp\Connection\Domain\ValueObject\McpClientName}) et chaque
 * redirection ({@see \App\Mcp\Connection\Domain\ValueObject\RedirectUri}) sont
 * jugés par leur value object, et le nombre de redirections distinctes suit
 * {@see \App\Mcp\Connection\Domain\Entity\McpClient::MAX_REDIRECT_URIS}.
 *
 * L'objet validé porte les propriétés publiques `?string $clientId` (absente,
 * ou `null`, à la révision : l'identifiant est immuable), `string $name` et
 * `list<string> $redirectUris`.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class ValidMcpClientFields extends Constraint
{
    public string $noRedirectMessage = 'Au moins une redirection est requise.';

    // « Dix » : épinglé sur McpClient::MAX_REDIRECT_URIS par un test.
    public string $tooManyRedirectsMessage = 'Dix redirections au plus.';

    public string $notAStringMessage = 'Chaque redirection doit être une chaîne de caractères.';

    public string $notAListMessage = 'Les redirections doivent former une liste.';

    public string $verifiedDomainClientIdMessage = 'Cet identifiant est celui d\'un client CIMD d\'un domaine vérifié : il ne se pré-enregistre pas.';

    #[Override]
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
