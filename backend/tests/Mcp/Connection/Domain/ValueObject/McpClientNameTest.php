<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Domain\ValueObject;

use App\Mcp\Connection\Domain\Exception\InvalidMcpClientNameException;
use App\Mcp\Connection\Domain\ValueObject\McpClientName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le nom d'un client MCP, affiché sur la page de consentement (#647) : 1 à
 * 100 caractères une fois rogné des blancs Unicode, en UTF-8 valide, sans
 * caractère de contrôle ni de format, ni séparateur de ligne ou de
 * paragraphe — qui tromperaient l'affichage.
 */
final class McpClientNameTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function acceptedNames(): iterable
    {
        yield 'simple' => ['Claude Code', 'Claude Code'];
        yield 'rogné des blancs Unicode, espace insécable intérieure gardée' => ["\u{3000}Claude\u{00A0}Code\u{00A0} ", "Claude\u{00A0}Code"];
        yield '100 caractères de deux octets' => [str_repeat('é', McpClientName::MAX_LENGTH), str_repeat('é', McpClientName::MAX_LENGTH)];
    }

    #[DataProvider('acceptedNames')]
    public function testANameIsTrimmedOfUnicodeBlanks(string $value, string $expected): void
    {
        self::assertSame($expected, (string) McpClientName::fromString($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedNames(): iterable
    {
        yield 'vide' => [''];
        yield 'blancs ASCII' => ['   '];
        yield 'espaces insécables' => ["\u{00A0}\u{00A0}"];
        yield 'espaces idéographiques' => ["\u{3000}\u{3000}"];
        yield '101 caractères' => [str_repeat('é', McpClientName::MAX_LENGTH + 1)];
        yield 'caractère de contrôle' => ["Claude\u{0007}Code"];
        yield 'caractère de format' => ["Claude\u{202E}Code"];
        yield 'UTF-8 invalide' => ["Claude \xC3"];
        yield 'séparateur de ligne' => ["Claude\u{2028}Code"];
        yield 'séparateur de paragraphe' => ["Claude\u{2029}Code"];
        yield 'saut de ligne intérieur' => ["Claude\nCode"];
    }

    #[DataProvider('refusedNames')]
    public function testANameOutsideTheRuleIsRefused(string $value): void
    {
        $this->expectException(InvalidMcpClientNameException::class);

        McpClientName::fromString($value);
    }
}
