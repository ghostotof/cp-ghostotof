<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\ValueObject;

use App\Mcp\Connection\Domain\Exception\InvalidClientMetadataDocumentException;
use App\Mcp\Connection\Domain\Exception\InvalidMcpClientNameException;
use App\Mcp\Connection\Domain\Exception\InvalidRedirectUriException;
use JsonException;

/**
 * Le document de métadonnées d'un client CIMD (#651), validé avant d'être cru
 * (draft-ietf-oauth-client-id-metadata-document-03).
 *
 * Il vient d'un hôte tiers : rien n'y est cru sans règle, et un seul défaut
 * refuse le document entier (échec fermé). Ce qu'on en retient est étroit :
 *  - `client_id` : une chaîne égale, au caractère près et sans normalisation,
 *    à l'URL demandée (§4.1) — sinon un hôte pourrait servir le document d'un
 *    autre client ;
 *  - un client public : ni `client_secret` ni `client_secret_expires_at`
 *    (même `null`), et `token_endpoint_auth_method` **déclaré** et valant exactement
 *    `none`. Absent, il vaudrait `client_secret_basic` (RFC 7591 §2, valeur par
 *    défaut) : un client confidentiel, que ce serveur ne sert pas. Un document
 *    qui se tait là est refusé : on refuse en cas de doute, le caractère public se déclare ;
 *  - le flux par code : `grant_types` et `response_types`, quand ils sont
 *    déclarés, sont des listes de chaînes qui contiennent `authorization_code`
 *    et `code` (absents, la valeur par défaut de la RFC 7591 est celle-là) ;
 *  - un nom admissible ({@see McpClientName}) et de 1 à 10 redirections, chacune
 *    admissible ({@see RedirectUri} : https, ou http en boucle locale).
 *
 * Tout autre champ (`logo_uri`, `scope`, `jwks_uri`…) est ignoré : rien de ce
 * que le client écrit là n'élargit ce que le serveur lui accorde.
 *
 * Le JSON est lu à huit niveaux d'imbrication au plus, et un message de refus ne
 * cite jamais une valeur du document. La taille du document est bornée par la
 * récupération (draft §10.11), pas ici : cette classe ne lit pas le réseau.
 */
final readonly class ClientMetadataDocument
{
    /** Le plus de redirections qu'un document déclare. */
    public const int MAX_REDIRECT_URIS = 10;

    /** L'imbrication JSON admise : le document réel en a un niveau, deux avec ses listes. */
    private const int MAX_JSON_DEPTH = 8;

    private const string AUTHORIZATION_CODE_GRANT = 'authorization_code';

    private const string CODE_RESPONSE = 'code';

    private const string PUBLIC_CLIENT_AUTH_METHOD = 'none';

    /** Les champs qui font d'un client un client confidentiel. */
    private const array SECRET_FIELDS = ['client_secret', 'client_secret_expires_at'];

    /**
     * @param list<RedirectUri> $redirectUris
     */
    private function __construct(
        public CimdClientId $clientId,
        public McpClientName $name,
        public array $redirectUris,
    ) {
    }

    /**
     * @throws InvalidClientMetadataDocumentException le document n'est pas admissible
     */
    public static function fromJson(CimdClientId $requested, string $json): self
    {
        $document = self::decode($json);

        if (!\array_key_exists('client_id', $document) || $document['client_id'] !== $requested->value()) {
            throw InvalidClientMetadataDocumentException::clientIdMismatch();
        }

        foreach (self::SECRET_FIELDS as $field) {
            if (\array_key_exists($field, $document)) {
                throw InvalidClientMetadataDocumentException::secretDeclared();
            }
        }

        // Requis : absent, la RFC 7591 §2 donne `client_secret_basic` (un client confidentiel).
        if (!\array_key_exists('token_endpoint_auth_method', $document)
            || self::PUBLIC_CLIENT_AUTH_METHOD !== $document['token_endpoint_auth_method']) {
            throw InvalidClientMetadataDocumentException::unsupportedAuthMethod();
        }

        if (\array_key_exists('grant_types', $document)
            && !self::isListOfStringsContaining($document['grant_types'], self::AUTHORIZATION_CODE_GRANT)) {
            throw InvalidClientMetadataDocumentException::unsupportedGrantTypes();
        }

        if (\array_key_exists('response_types', $document)
            && !self::isListOfStringsContaining($document['response_types'], self::CODE_RESPONSE)) {
            throw InvalidClientMetadataDocumentException::unsupportedResponseTypes();
        }

        return new self(
            $requested,
            self::name($document),
            self::redirectUris($document),
        );
    }

    /**
     * @return array<array-key, mixed> un objet JSON non vide
     */
    private static function decode(string $json): array
    {
        try {
            $document = json_decode($json, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            // Le message du décodeur ne cite pas le texte ; la cause reste chaînée.
            throw InvalidClientMetadataDocumentException::notAnObject($failure);
        }

        // `{}` et `[]` se décodent tous deux en tableau vide, une liste n'est pas un objet.
        if (!\is_array($document) || [] === $document || array_is_list($document)) {
            throw InvalidClientMetadataDocumentException::notAnObject();
        }

        return $document;
    }

    /**
     * @param array<array-key, mixed> $document
     */
    private static function name(array $document): McpClientName
    {
        $name = $document['client_name'] ?? null;

        if (!\is_string($name)) {
            throw InvalidClientMetadataDocumentException::invalidName();
        }

        try {
            return McpClientName::fromString($name);
        } catch (InvalidMcpClientNameException $refusal) {
            throw InvalidClientMetadataDocumentException::invalidName($refusal);
        }
    }

    /**
     * @param array<array-key, mixed> $document
     *
     * @return list<RedirectUri>
     */
    private static function redirectUris(array $document): array
    {
        $declared = $document['redirect_uris'] ?? null;

        if (!\is_array($declared) || !array_is_list($declared)
            || [] === $declared || \count($declared) > self::MAX_REDIRECT_URIS) {
            throw InvalidClientMetadataDocumentException::invalidRedirectUris();
        }

        $redirectUris = [];

        foreach ($declared as $redirectUri) {
            if (!\is_string($redirectUri)) {
                throw InvalidClientMetadataDocumentException::invalidRedirectUris();
            }

            try {
                $redirectUris[] = RedirectUri::fromString($redirectUri);
            } catch (InvalidRedirectUriException $refusal) {
                throw InvalidClientMetadataDocumentException::invalidRedirectUris($refusal);
            }
        }

        return $redirectUris;
    }

    /**
     * Une liste JSON dont tous les membres sont des chaînes, `$expected` parmi elles.
     */
    private static function isListOfStringsContaining(mixed $declared, string $expected): bool
    {
        if (!\is_array($declared) || !array_is_list($declared)) {
            return false;
        }

        foreach ($declared as $member) {
            if (!\is_string($member)) {
                return false;
            }
        }

        return \in_array($expected, $declared, true);
    }
}
