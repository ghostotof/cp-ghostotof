<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\Http;

use Mcp\Schema\JsonRpc\Error;

/**
 * Ce que le serveur MCP refuse avant le SDK (#644), et le message rendu au
 * client — lu sans jeton, donc au palier anonyme (ADR 0006 D8) :
 * {@see InboundMessageRefusalListener}.
 */
enum InboundMessageRefusal
{
    /** Lot JSON-RPC de plusieurs messages. */
    case SeveralMessages;

    /** `initialize` dont `clientInfo` et `capabilities` dépassent {@see self::MAX_DECLARATION_BYTES}. */
    case OversizedDeclaration;

    /** Réponse (ou erreur) du client : ce serveur ne lui envoie aucune requête. */
    case ClientResponse;

    /** `params._meta` présent mais pas un objet (#664). */
    case MalformedMeta;

    /** `clientInfo` de la révision sans session que le SDK ne sait pas lire (#664). */
    case MalformedClientInfo;

    /** `subscriptions/listen` : ce serveur n'émet aucune notification (#675). */
    case SubscriptionListen;

    /**
     * Octets que les déclarations d'un `initialize` peuvent occuper en
     * session. Un vrai client en déclare moins d'un Kio (nom, version,
     * `roots`, `elicitation`) ; au-delà, c'est le vecteur qu'un client
     * anonyme utilisait pour gonfler `cache_items` (audit de #644).
     */
    public const int MAX_DECLARATION_BYTES = 4096;

    public function message(): string
    {
        return match ($this) {
            self::SeveralMessages => 'JSON-RPC batches are not supported: send one message per request.',
            self::OversizedDeclaration => \sprintf('The initialize request declares more than %d bytes of client information and capabilities.', self::MAX_DECLARATION_BYTES),
            self::ClientResponse => 'This server sends no requests to the client: responses are not accepted.',
            self::MalformedMeta => 'The "_meta" member of "params" must be an object.',
            self::MalformedClientInfo => 'The declared client information must name the client and its version as non-empty strings.',
            self::SubscriptionListen => 'This server sends no notifications: "subscriptions/listen" is not served.',
        };
    }

    /**
     * L'erreur JSON-RPC rendue au client. Un message mal formé ou démesuré l'est
     * comme le transport du SDK refuse les siens : `-32600` sans identifiant.
     * Un `listen` est une requête bien formée dont la méthode n'est pas servie :
     * `-32601` avec son identifiant, comme le SDK pour une méthode qu'il ne sert
     * pas — pour lui, le client doit cesser de demander (#675).
     */
    public function error(string|int|null $requestId): Error
    {
        return match ($this) {
            self::SubscriptionListen => Error::forMethodNotFound($this->message(), $requestId),
            default => Error::forInvalidRequest($this->message()),
        };
    }

    /**
     * Ce que pèsent `clientInfo` et `capabilities` une fois en session :
     * `json_encode` sans option, comme `Session::save()` — chaque caractère
     * hors ASCII y prend six octets (`é`).
     *
     * @param array<mixed> $clientInfo
     * @param array<mixed> $capabilities
     */
    public static function declarationBytes(array $clientInfo, array $capabilities): int
    {
        return \strlen(json_encode([$clientInfo, $capabilities], \JSON_THROW_ON_ERROR));
    }
}
