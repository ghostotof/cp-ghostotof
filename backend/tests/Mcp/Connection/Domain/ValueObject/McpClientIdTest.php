<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Domain\ValueObject;

use App\Mcp\Connection\Domain\Exception\InvalidMcpClientIdException;
use App\Mcp\Connection\Domain\ValueObject\McpClientId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * L'identifiant public d'un client MCP (#647) : 1 à 255 caractères d'un
 * alphabet d'URL sans `?`, `#`, `%` ni espace — un identifiant court ou l'URL
 * d'un document de métadonnées. Écrit dans le domaine, partagé par la
 * commande d'enregistrement et le futur backoffice (#652).
 */
final class McpClientIdTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedIds(): iterable
    {
        yield 'court' => ['claude-code'];
        yield 'URL de métadonnées' => ['https://app.example/client.json'];
        yield 'un caractère' => ['a'];
        yield '255 caractères' => [str_repeat('a', McpClientId::MAX_LENGTH)];
    }

    #[DataProvider('acceptedIds')]
    public function testAnIdOfTheAlphabetIsKeptAsIs(string $value): void
    {
        self::assertSame($value, (string) McpClientId::fromString($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedIds(): iterable
    {
        yield 'vide' => [''];
        yield 'espace' => ['claude code'];
        yield 'saut de ligne final' => ["claude-code\n"];
        yield 'octet nul' => ["claude\0code"];
        yield 'requête' => ['https://app.example/c.json?x=1'];
        yield 'fragment' => ['https://app.example/c.json#x'];
        yield 'pourcentage' => ['https://app.example/c%20.json'];
        yield 'non ASCII' => ['clauðe'];
        yield '256 caractères' => [str_repeat('a', McpClientId::MAX_LENGTH + 1)];
    }

    #[DataProvider('refusedIds')]
    public function testAnIdOutsideTheAlphabetIsRefusedWithoutQuotingIt(string $value): void
    {
        try {
            McpClientId::fromString($value);
            self::fail('Identifiant accepté à tort.');
        } catch (InvalidMcpClientIdException $exception) {
            self::assertSame(InvalidMcpClientIdException::malformed()->getMessage(), $exception->getMessage());
        }
    }

    public function testTwoIdsOfTheSameValueAreEqual(): void
    {
        self::assertTrue(McpClientId::fromString('claude-code')->equals(McpClientId::fromString('claude-code')));
        self::assertFalse(McpClientId::fromString('claude-code')->equals(McpClientId::fromString('other')));
    }
}
