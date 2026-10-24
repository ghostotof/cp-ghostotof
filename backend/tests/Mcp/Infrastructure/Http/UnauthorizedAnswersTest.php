<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Infrastructure\Http;

use App\Mcp\Connection\Application\BearerChallenge;
use App\Mcp\Connection\Application\OAuthEndpoints;
use App\Mcp\Infrastructure\Http\ServerAnswers;
use App\Mcp\Infrastructure\Http\UnauthorizedAnswers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le 401 de `/api/mcp`, écrit une fois pour ses deux auteurs (#648) : le même
 * gabarit, seul le défi change. Les chemins HTTP réels sont éprouvés par
 * DeferredAuthorizationListenerTest et McpBearerAuthenticatorTest.
 */
final class UnauthorizedAnswersTest extends TestCase
{
    private const string METADATA = 'Bearer resource_metadata="https://site.example/.well-known/oauth-protected-resource/api/mcp", scope="nominative:read"';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function answers(): iterable
    {
        yield 'sans jeton' => ['missingToken', self::METADATA];
        yield 'jeton refusé' => ['refusedToken', self::METADATA.', error="invalid_token"'];
    }

    #[DataProvider('answers')]
    public function testEachAnswerIsAnEmptyUnstored401CarryingItsChallengeAndRecorded(string $answer, string $challenge): void
    {
        $serverAnswers = new ServerAnswers();
        $unauthorized = new UnauthorizedAnswers(new BearerChallenge(new OAuthEndpoints('https://site.example', 'https://site.example')), $serverAnswers);

        $response = 'missingToken' === $answer ? $unauthorized->missingToken() : $unauthorized->refusedToken();

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        self::assertSame($challenge, $response->headers->get('WWW-Authenticate'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($serverAnswers->recorded($response), 'Le journal d\'appels doit la lire comme une réponse du serveur.');
    }
}
