<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Entities\Traits\AuthCodeTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

/**
 * Le code d'autorisation tel que league le manipule pendant une requête. Le
 * client en reçoit une charge chiffrée par league (clé d'OAuthEncryptionKey) ;
 * la base n'en garde que l'empreinte de l'identifiant (AuthCodeRepository).
 */
final class AuthCode implements AuthCodeEntityInterface
{
    use AuthCodeTrait;
    use EntityTrait;
    use TokenEntityTrait;
}
