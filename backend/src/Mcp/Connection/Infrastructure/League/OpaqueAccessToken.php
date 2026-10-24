<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use League\OAuth2\Server\CryptKeyInterface;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;
use SensitiveParameter;

/**
 * Un jeton d'accès opaque (#647) : la chaîne remise au client **est** son
 * identifiant, les 80 caractères hexadécimaux que league tire
 * (`bin2hex(random_bytes(40))`). Rien n'y est lisible, rien n'y est signé :
 * l'authentificateur de `/api/mcp` en retrouve la ligne par son empreinte et
 * relit la connexion à chaque requête (ADR 0006 D4), ce qu'un JWT
 * autoporteur dispenserait de faire — et qui seul permet la révocation.
 */
final class OpaqueAccessToken implements AccessTokenEntityInterface
{
    use EntityTrait;
    use TokenEntityTrait;

    /**
     * league transmet toujours sa clé au jeton ; un jeton opaque n'en garde
     * rien. La clé de ce serveur (NoSigningKey) lèverait à la lecture.
     */
    public function setPrivateKey(#[SensitiveParameter] CryptKeyInterface $privateKey): void
    {
    }

    public function toString(): string
    {
        return $this->getIdentifier();
    }
}
