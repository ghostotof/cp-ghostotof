<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\RefreshTokenTrait;

/**
 * Le jeton de rafraîchissement tel que league le manipule pendant une
 * requête. Le client en reçoit une charge chiffrée par league (clé
 * d'OAuthEncryptionKey) ; la base n'en garde que l'empreinte de
 * l'identifiant (RefreshTokenRepository).
 */
final class RefreshToken implements RefreshTokenEntityInterface
{
    use EntityTrait;
    use RefreshTokenTrait;
}
