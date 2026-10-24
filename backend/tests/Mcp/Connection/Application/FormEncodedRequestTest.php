<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Application;

use App\Mcp\Connection\Application\FormEncodedRequest;
use League\OAuth2\Server\Exception\OAuthServerException;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Le corps des points de jeton et de révocation (#647) : un formulaire, et
 * league ne reçoit que les paramètres tirés du **corps brut** (`parse_str`),
 * jamais le corps déjà analysé que porte la requête PSR-7.
 *
 * Le pont PSR-7 remplit ce corps analysé depuis `$_POST` ou, sur le format
 * `json`, depuis le JSON : s'il passait tel quel, league servirait des
 * paramètres que le contrôle de répétition, qui lit le corps brut, n'a jamais
 * vus. Chaque cas donne donc à la requête un corps analysé **différent** du
 * corps brut, et exige que league voie le second.
 */
final class FormEncodedRequestTest extends TestCase
{
    private const array READ = ['grant_type', 'code', 'client_id'];

    private const string FORM = 'application/x-www-form-urlencoded';

    /**
     * @return iterable<string, array{array<string, string>|null}>
     */
    public static function parsedBodiesOtherThanTheRawOne(): iterable
    {
        // Ce que le pont aurait tiré d'un autre analyseur, ou d'un `$_POST`
        // que PHP a rempli autrement.
        yield 'un autre client' => [['grant_type' => 'authorization_code', 'code' => 'c', 'client_id' => 'https://forged.example.test/metadata.json']];
        yield 'un paramètre de plus' => [['grant_type' => 'authorization_code', 'code' => 'c', 'client_id' => 'https://client.example.test/metadata.json', 'resource' => 'https://other.example.test/api/mcp']];
        // `enable_post_data_reading` à Off : `$_POST` vide.
        yield 'vide' => [[]];
        yield 'absent' => [null];
    }

    /**
     * @param array<string, string>|null $parsedBody
     */
    #[DataProvider('parsedBodiesOtherThanTheRawOne')]
    public function testLeagueGetsTheParametersOfTheRawBody(?array $parsedBody): void
    {
        $raw = 'grant_type=authorization_code&code=c%2Bd+e&client_id=https%3A%2F%2Fclient.example.test%2Fmetadata.json';

        $parsed = FormEncodedRequest::parse($this->request($raw, $parsedBody), self::READ)->getParsedBody();

        // `parse_str` lit `+` comme une espace et `%2B` comme un `+`, comme
        // tout analyseur de formulaire.
        self::assertSame([
            'grant_type' => 'authorization_code',
            'code' => 'c+d e',
            'client_id' => 'https://client.example.test/metadata.json',
        ], $parsed);
    }

    /** Un corps vide donne des paramètres vides, jamais ceux de la requête. */
    public function testAnEmptyRawBodyGivesNoParameters(): void
    {
        $parsed = FormEncodedRequest::parse($this->request('', ['client_id' => 'https://forged.example.test/metadata.json']), self::READ)->getParsedBody();

        self::assertSame([], $parsed);
    }

    /** Un autre type de contenu : `invalid_request`, avant toute lecture. */
    public function testAnotherMediaTypeIsInvalidRequest(): void
    {
        $request = new ServerRequest('POST', '/api/oauth/token', ['Content-Type' => 'application/json'], '{"grant_type":"authorization_code"}');

        try {
            FormEncodedRequest::parse($request, self::READ);
            self::fail('Un corps JSON aurait dû être refusé.');
        } catch (OAuthServerException $exception) {
            self::assertSame('invalid_request', $exception->getErrorType());
        }
    }

    /**
     * @param array<string, string>|null $parsedBody
     */
    private function request(string $raw, ?array $parsedBody): ServerRequestInterface
    {
        $request = new ServerRequest('POST', '/api/oauth/token', ['Content-Type' => self::FORM], $raw);

        return null === $parsedBody ? $request : $request->withParsedBody($parsedBody);
    }
}
