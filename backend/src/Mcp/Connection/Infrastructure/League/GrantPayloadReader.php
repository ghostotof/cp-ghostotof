<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use InvalidArgumentException;
use League\OAuth2\Server\CryptTrait;
use SensitiveParameter;
use Symfony\Component\Uid\Uuid;

/**
 * Lit ce qu'un code d'autorisation ou un jeton de rafraîchissement remis par
 * league porte (#647, #725) : la charge que league a chiffrée sous la clé
 * d'OAuthEncryptionKey (`AuthCodeGrant`, `BearerTokenResponse`), déchiffrée
 * par le même code que league (`CryptTrait`), donc le même algorithme et la
 * même lecture de la clé — une clé defuse, jamais un mot de passe.
 *
 * Le serveur seul détient cette clé : un artefact qu'il déchiffre est
 * authentique, il l'a émis. Un artefact illisible (une autre clé, un texte
 * quelconque, une charge modifiée, un JSON sans le champ lu) n'est pas une
 * erreur : null, que la révocation tient pour inconnu (RFC 7009 §2.2) et que
 * l'audit d'un refus tient pour ne désignant personne. Une panne de
 * l'environnement de chiffrement, que `CryptTrait` lève en LogicException,
 * n'est pas rattrapée : un défaut du serveur, jamais un artefact illisible.
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
final class GrantPayloadReader
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
    public function refreshTokenIdentifierOf(#[SensitiveParameter] string $refreshToken): ?string
    {
        $identifier = $this->payloadOf($refreshToken)['refresh_token_id'] ?? null;

        return \is_string($identifier) && '' !== $identifier ? $identifier : null;
    }

    /**
     * La connexion qu'un code ou un jeton de rafraîchissement désigne : son
     * `user_id`, l'UUID que ConnectionAsUser a remis à league, écrit tel
     * quel (RFC 4122, minuscules) ; null si l'artefact est illisible ou n'en
     * porte pas.
     */
    public function connectionIdOf(#[SensitiveParameter] string $grant): ?Uuid
    {
        $identifier = $this->payloadOf($grant)['user_id'] ?? null;
        if (!\is_string($identifier) || !Uuid::isValid($identifier)) {
            return null;
        }

        $connection = Uuid::fromString($identifier);

        return $connection->toRfc4122() === $identifier ? $connection : null;
    }

    /**
     * Le défi PKCE qu'un code porte (`code_challenge`, RFC 7636 §4.2), quand
     * sa méthode est exactement `S256` — la seule que le point d'autorisation
     * admet (LoopbackAwareAuthCodeGrant) — et que c'est une chaîne non vide ;
     * null sinon : un code illisible, sans défi, ou d'une autre méthode ne
     * permet de prouver la possession d'aucun vérificateur (#726).
     */
    public function s256ChallengeOf(#[SensitiveParameter] string $code): ?string
    {
        $payload = $this->payloadOf($code);
        $challenge = $payload['code_challenge'] ?? null;

        return 'S256' === ($payload['code_challenge_method'] ?? null) && \is_string($challenge) && '' !== $challenge ? $challenge : null;
    }

    /**
     * La charge déchiffrée, si c'est un objet JSON ; `[]` sinon.
     *
     * @return array<array-key, mixed>
     */
    private function payloadOf(#[SensitiveParameter] string $grant): array
    {
        $this->setEncryptionKey($this->key->key());

        try {
            $json = $this->decrypt($grant);
        } catch (InvalidArgumentException) {
            return [];
        } finally {
            // Sans argument, la clé retombe à null : retirée jusqu'à la
            // prochaine lecture.
            $this->setEncryptionKey();
        }

        $payload = json_decode($json, true);

        return \is_array($payload) && !array_is_list($payload) ? $payload : [];
    }
}
