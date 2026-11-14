<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Exception;

use InvalidArgumentException;
use Throwable;

/**
 * Le document de métadonnées d'un client CIMD (#651) n'est pas admissible.
 *
 * Chaque cause a sa fabrique et son message constant, qui nomme le champ en
 * cause et jamais sa valeur : le document vient d'un hôte tiers, ce qu'il
 * contient n'a rien à faire dans un message, un journal ni une réponse. La
 * cause d'un décodage ou d'une valeur objet reste chaînée (`previous`) pour le
 * diagnostic, sans que son message cite une valeur non plus.
 *
 * Ce refus est un défaut du document, pas du serveur : l'appelant le traduit
 * en refus de la requête d'autorisation, jamais en erreur 500.
 */
final class InvalidClientMetadataDocumentException extends InvalidArgumentException
{
    private function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** Pas du JSON, trop imbriqué, ou un JSON qui n'est pas un objet non vide. */
    public static function notAnObject(?Throwable $previous = null): self
    {
        return new self('The client metadata document is not a JSON object.', $previous);
    }

    /** `client_id` absent, pas une chaîne, ou différent de l'URL demandée (draft §4.1). */
    public static function clientIdMismatch(): self
    {
        return new self('The client metadata document does not declare the requested client_id.');
    }

    /** `client_secret` ou `client_secret_expires_at` déclaré : un client confidentiel. */
    public static function secretDeclared(): self
    {
        return new self('The client metadata document declares a client_secret.');
    }

    /** `token_endpoint_auth_method` autre que `none`. */
    public static function unsupportedAuthMethod(): self
    {
        return new self('The client metadata document declares a token_endpoint_auth_method other than none.');
    }

    /** `grant_types` déclaré sans `authorization_code`. */
    public static function unsupportedGrantTypes(): self
    {
        return new self('The client metadata document declares grant_types without authorization_code.');
    }

    /** `response_types` déclaré sans `code`. */
    public static function unsupportedResponseTypes(): self
    {
        return new self('The client metadata document declares response_types without code.');
    }

    /** `client_name` absent ou inadmissible. */
    public static function invalidName(?Throwable $previous = null): self
    {
        return new self('The client metadata document has no valid client_name.', $previous);
    }

    /** `redirect_uris` absent, vide, trop long, ou dont un membre est inadmissible. */
    public static function invalidRedirectUris(?Throwable $previous = null): self
    {
        return new self('The client metadata document has no valid redirect_uris.', $previous);
    }
}
