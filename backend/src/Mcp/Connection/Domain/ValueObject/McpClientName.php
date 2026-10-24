<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\ValueObject;

use App\Mcp\Connection\Domain\Exception\InvalidMcpClientNameException;
use Stringable;

/**
 * Le nom d'un client MCP (#647), affiché sur la page de consentement.
 *
 * 1 à 100 caractères une fois rogné, en UTF-8 valide (le motif `u` refuse le
 * reste), sans caractère de contrôle ni de format (`\p{C}`, l'inversion de
 * sens U+202E comprise) ni séparateur de ligne ou de paragraphe (U+2028,
 * U+2029 : `\p{Zl}`, `\p{Zp}`), qui tromperaient l'affichage. Les espaces
 * Unicode (`\p{Zs}`, insécable comprise) restent admises à l'intérieur.
 *
 * Rogné aux deux bouts des blancs d'Unicode (`\p{Z}`, U+00A0 et U+3000
 * compris) et de `\s`, que trim() ne connaît pas au-delà de l'ASCII : un nom
 * fait d'espaces insécables n'est pas un nom.
 *
 * Écrit dans le domaine, une fois : la commande d'enregistrement et le futur
 * backoffice (#652) le partagent, et la colonne `mcp_client.name` en prend la
 * borne (McpClient::NAME_MAX_LENGTH).
 */
final readonly class McpClientName implements Stringable
{
    public const int MAX_LENGTH = 100;

    private const string PATTERN = '/\A[^\p{C}\p{Zl}\p{Zp}]{1,'.self::MAX_LENGTH.'}\z/u';

    private const string EDGE_BLANKS = '/\A[\p{Z}\s]+|[\p{Z}\s]+\z/u';

    private function __construct(
        private string $value,
    ) {
    }

    /**
     * @throws InvalidMcpClientNameException
     */
    public static function fromString(string $value): self
    {
        // preg_replace() rend null, et preg_match() false, sur de l'UTF-8
        // invalide : refusé comme le reste.
        $trimmed = preg_replace(self::EDGE_BLANKS, '', $value);

        if (null === $trimmed || 1 !== preg_match(self::PATTERN, $trimmed)) {
            throw InvalidMcpClientNameException::malformed();
        }

        return new self($trimmed);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
