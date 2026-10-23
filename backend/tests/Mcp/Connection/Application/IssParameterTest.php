<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Application;

use App\Mcp\Connection\Application\IssParameter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le paramètre `iss` (RFC 9207) ajouté à une redirection vers le client : il
 * dit au client quel serveur d'autorisation lui répond, contre la confusion de
 * serveurs (« mix-up »). Ajouté à la requête, les paramètres présents gardés
 * dans leur ordre, l'émetteur encodé comme le reste de la requête.
 */
final class IssParameterTest extends TestCase
{
    private const string ISSUER = 'https://site.example.test/api/oauth';

    private const string ENCODED_ISSUER = 'https%3A%2F%2Fsite.example.test%2Fapi%2Foauth';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function locations(): iterable
    {
        yield 'sans requête' => ['https://client.example.test/cb', 'https://client.example.test/cb?iss='.self::ENCODED_ISSUER];
        yield 'avec une requête' => ['http://localhost:49152/cb?state=s&error=invalid_request', 'http://localhost:49152/cb?state=s&error=invalid_request&iss='.self::ENCODED_ISSUER];
        yield 'requête vide' => ['http://localhost/cb?', 'http://localhost/cb?&iss='.self::ENCODED_ISSUER];
        yield 'fragment conservé après la requête' => ['https://client.example.test/cb?a=1#frag', 'https://client.example.test/cb?a=1&iss='.self::ENCODED_ISSUER.'#frag'];
        yield 'fragment sans requête' => ['https://client.example.test/cb#frag', 'https://client.example.test/cb?iss='.self::ENCODED_ISSUER.'#frag'];
        yield 'point d\'interrogation dans le fragment' => ['https://client.example.test/cb#a?b', 'https://client.example.test/cb?iss='.self::ENCODED_ISSUER.'#a?b'];
    }

    #[DataProvider('locations')]
    public function testItAppendsTheIssuerToTheQuery(string $location, string $expected): void
    {
        self::assertSame($expected, IssParameter::append($location, self::ISSUER));
    }

    public function testTheIssuerReadsBackAsIs(): void
    {
        $location = IssParameter::append('http://localhost/cb?state=a%26b', self::ISSUER);

        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);

        self::assertSame(['state' => 'a&b', 'iss' => self::ISSUER], $query);
    }
}
