<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use League\OAuth2\Server\CryptKeyInterface;

/**
 * La « clé privée » que league exige à la construction de son serveur
 * d'autorisation, quand les jetons d'accès sont opaques (#647) : il n'y en a
 * pas, et chaque lecture lève.
 *
 * Vérifié dans league 9.4.1 : AuthorizationServer, ses grants et
 * BearerTokenResponse ne font que **transmettre** la clé (`setPrivateKey()`),
 * sans jamais la lire ; seuls la lisent la mise en JWT d'un jeton
 * (`AccessTokenTrait::initJwtConfiguration()`, qu'OpaqueAccessToken n'utilise
 * pas) et le ResourceServer (clé publique, non utilisé). Échouer franchement
 * plutôt que rendre une chaîne vide : le jour où un chemin en voudrait une,
 * il ne signerait rien avec une clé vide ou de repli.
 */
final class NoSigningKey implements CryptKeyInterface
{
    public function getKeyPath(): string
    {
        throw AccessTokensAreNotSignedException::noSigningKey();
    }

    public function getPassPhrase(): ?string
    {
        throw AccessTokensAreNotSignedException::noSigningKey();
    }

    public function getKeyContents(): string
    {
        throw AccessTokensAreNotSignedException::noSigningKey();
    }
}
