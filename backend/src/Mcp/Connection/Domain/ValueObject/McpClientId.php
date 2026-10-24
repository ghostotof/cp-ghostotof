<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\ValueObject;

use App\Mcp\Connection\Domain\Exception\InvalidMcpClientIdException;
use Stringable;

/**
 * L'identifiant public d'un client MCP (`client_id`, #647) : un identifiant
 * court (`claude-code`) ou l'URL d'un document de métadonnées (CIMD, #651).
 *
 * 1 à 255 caractères d'un alphabet d'URL sans `?`, `#`, `%` ni espace : ce
 * que la page de consentement affiche et que le point de jeton compare reste
 * lisible tel quel, sans décodage ni ambiguïté. Écrit dans le domaine, une
 * fois : la commande d'enregistrement et le futur backoffice (#652) le
 * partagent, et la colonne `mcp_client.client_id` en prend la borne
 * (McpClient::CLIENT_ID_MAX_LENGTH).
 */
final readonly class McpClientId implements Stringable
{
    public const int MAX_LENGTH = 255;

    private const string PATTERN = '#\A[A-Za-z0-9._:/-]{1,'.self::MAX_LENGTH.'}\z#';

    private function __construct(
        private string $value,
    ) {
    }

    /**
     * @throws InvalidMcpClientIdException
     */
    public static function fromString(string $value): self
    {
        if (1 !== preg_match(self::PATTERN, $value)) {
            throw InvalidMcpClientIdException::malformed();
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
