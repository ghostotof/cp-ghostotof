<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use League\OAuth2\Server\Entities\UserEntityInterface;

/**
 * « L'utilisateur » d'une demande refusée (#647). league exige un utilisateur
 * pour achever toute demande, refus compris (`completeAuthorizationRequest()`
 * lève une LogicException sans lui), mais ne le lit que pour émettre un code.
 * Un refus ne crée pas de connexion, donc pas de ConnectionAsUser : cet
 * utilisateur-ci n'en désigne aucune, et son identifiant n'est pas un UUID —
 * aucun dépôt ne pourrait le prendre pour une connexion.
 */
final readonly class DeniedRequestUser implements UserEntityInterface
{
    private const string IDENTIFIER = 'no-connection';

    /**
     * @return non-empty-string
     */
    public function getIdentifier(): string
    {
        return self::IDENTIFIER;
    }
}
