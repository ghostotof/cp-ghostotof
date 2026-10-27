<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Application;

use App\Mcp\Connection\Application\FormEncodedRequest;
use ArrayObject;
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

    /**
     * Les blancs que league retire d'une valeur (`AbstractGrant::parseParam()`,
     * `trim()`) avant de la lire : en tête ou en fin d'un paramètre lu.
     *
     * @return iterable<string, array{string}>
     */
    public static function valuesWithSurroundingBlanks(): iterable
    {
        yield 'espace en tête' => ['code=%20c'];
        yield 'espace en fin, par +' => ['code=c+'];
        yield 'saut de ligne en fin' => ['code=c%0A'];
        yield 'retour chariot' => ['code=c%0D'];
        yield 'tabulation' => ['code=%09c'];
        yield 'tabulation verticale' => ['code=c%0B'];
        yield 'octet nul' => ['code=c%00'];
        yield 'client_id entouré' => ['code=c&client_id=%09https%3A%2F%2Fclient.example.test%2Fmetadata.json'];
    }

    /**
     * Refusée, jamais corrigée (#725, la règle de QueryParameterPollution) :
     * league lit la valeur sans ses blancs, et tout autre lecteur du même
     * corps — l'audit d'un refus — la lirait avec. Un code entouré de blancs
     * échappait ainsi à l'audit tout en atteignant league, et un `client_id`
     * suivi de 50 000 tabulations entrait tel quel au journal (mesuré).
     */
    #[DataProvider('valuesWithSurroundingBlanks')]
    public function testAReadValueWithSurroundingBlanksIsInvalidRequest(string $raw): void
    {
        try {
            FormEncodedRequest::parse($this->request('grant_type=authorization_code&'.$raw, null), self::READ);
            self::fail('Une valeur entourée de blancs aurait dû être refusée.');
        } catch (OAuthServerException $exception) {
            self::assertSame('invalid_request', $exception->getErrorType());
        }
    }

    /** Un blanc à l'intérieur d'une valeur, ou dans un paramètre que le point ne lit pas, ne change rien. */
    public function testBlanksInsideAValueOrOutsideTheReadParametersAreKept(): void
    {
        $parsed = FormEncodedRequest::parse($this->request('grant_type=authorization_code&code=c+d&state=%20s%20', null), self::READ)->getParsedBody();

        self::assertSame(['grant_type' => 'authorization_code', 'code' => 'c d', 'state' => ' s '], $parsed);
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
     * stringParameter() (#726, CR7) : la chaîne du corps analysé, telle
     * quelle — vide comprise, chaque lecteur juge la sienne.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function stringValues(): iterable
    {
        yield 'une valeur' => ['grant_type=authorization_code&code=abc', 'abc'];
        yield 'une valeur vide' => ['grant_type=authorization_code&code=', ''];
        yield 'un blanc intérieur' => ['grant_type=authorization_code&code=c+d', 'c d'];
    }

    #[DataProvider('stringValues')]
    public function testAStringParameterIsReadFromTheParsedBody(string $raw, string $expected): void
    {
        $parsed = FormEncodedRequest::parse($this->request($raw, null), self::READ);

        self::assertSame($expected, FormEncodedRequest::stringParameter($parsed, 'code'));
    }

    /**
     * Rien d'autre qu'une chaîne : un paramètre absent, un tableau
     * (`parse_str()` lit `x[]=…` ainsi ; parse() le refuse pour un nom lu,
     * stringParameter() ne s'y fie pas), un entier d'un corps forgé, ou un
     * corps qui n'a pas été analysé en tableau.
     *
     * @return iterable<string, array{ServerRequestInterface}>
     */
    public static function noStringValue(): iterable
    {
        $form = new ServerRequest('POST', '/api/oauth/token', ['Content-Type' => self::FORM]);

        yield 'absent' => [$form->withParsedBody(['grant_type' => 'authorization_code'])];
        yield 'en tableau' => [$form->withParsedBody(['code' => ['abc']])];
        yield 'un entier' => [$form->withParsedBody(['code' => 42])];
        yield 'aucun corps analysé' => [$form];
        yield 'un corps objet' => [$form->withParsedBody(new ArrayObject(['code' => 'abc']))];
    }

    #[DataProvider('noStringValue')]
    public function testAnythingButAStringParameterIsNull(ServerRequestInterface $request): void
    {
        self::assertNull(FormEncodedRequest::stringParameter($request, 'code'));
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
