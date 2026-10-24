<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use InvalidArgumentException;
use League\OAuth2\Server\CryptTrait;
use SensitiveParameter;

/**
 * Lit ce qu'un jeton de rafraîchissement remis par league porte (#647) : la
 * charge que league a chiffrée sous la clé d'OAuthEncryptionKey
 * (`BearerTokenResponse`), déchiffrée par le même code que league
 * (`CryptTrait`), donc le même algorithme et la même lecture de la clé — une
 * clé defuse, jamais un mot de passe.
 *
 * Un jeton illisible (une autre clé, un texte quelconque, une charge
 * modifiée, un JSON sans identifiant) n'est pas une erreur : null, que la
 * révocation tient pour inconnu (RFC 7009 §2.2). Une panne de l'environnement
 * de chiffrement, que `CryptTrait` lève en LogicException, n'est pas
 * rattrapée : un défaut du serveur, jamais un jeton illisible.
 *
 * La clé n'est posée que le temps de la lecture, puis retirée : dérivée à
 * l'usage (le `cache:clear` du `docker build` n'a pas d'`APP_SECRET`, #474),
 * ce lecteur ne la garde pas entre deux lectures. Ce n'est pas une promesse
 * sur le processus : **limite acceptée** (`.claude/rules/backend-integrite.md`),
 * league garde la même clé dérivée dans ses propres propriétés privées
 * (`AuthorizationServer`, ses grants, sa réponse, par `CryptTrait`) pour
 * toute la vie du service, qu'un dump de ces objets afficherait — rien du
 * projet ne les dumpe.
 */
final class RefreshTokenPayloadReader
{
    use CryptTrait;

    public function __construct(
        private readonly OAuthEncryptionKey $key,
    ) {
    }

    /**
     * L'identifiant du jeton de rafraîchissement (`refresh_token_id`), celui
     * dont la base garde l'empreinte ; null si le jeton est illisible.
     */
    public function identifierOf(#[SensitiveParameter] string $refreshToken): ?string
    {
        $this->setEncryptionKey($this->key->key());

        try {
            $json = $this->decrypt($refreshToken);
        } catch (InvalidArgumentException) {
            return null;
        } finally {
            // Sans argument, la clé retombe à null : retirée jusqu'à la
            // prochaine lecture.
            $this->setEncryptionKey();
        }

        $payload = json_decode($json, true);
        $identifier = \is_array($payload) ? ($payload['refresh_token_id'] ?? null) : null;

        return \is_string($identifier) && '' !== $identifier ? $identifier : null;
    }
}
