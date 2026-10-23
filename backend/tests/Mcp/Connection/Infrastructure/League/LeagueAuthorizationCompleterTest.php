<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Infrastructure\League;

use App\Mcp\Connection\Application\AuthorizationRequestSummary;
use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Infrastructure\League\DeniedRequestUser;
use App\Mcp\Connection\Infrastructure\League\LeagueAuthorizationCompleter;
use App\Mcp\Connection\Infrastructure\League\UnexpectedAuthorizationResponseException;
use App\Security\User\Domain\Entity\CpgUser;
use DateTimeImmutable;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * L'achèvement par league (#647), sur un serveur d'autorisation doublé : ce
 * que la doublure rend ou lève est ce que league rendrait ou lèverait. Le
 * chemin réel (accord, refus) est couvert de bout en bout par
 * ConsentControllerTest ; ici, les contrats rompus, qu'aucune requête ne
 * provoque — chacun un défaut du serveur nommé, jamais une redirection.
 */
final class LeagueAuthorizationCompleterTest extends TestCase
{
    private const string REDIRECT = 'http://localhost:49152/callback';

    private AuthorizationRequest $authorization;

    protected function setUp(): void
    {
        $this->authorization = new AuthorizationRequest();
    }

    public function testAnApprovalHandsTheConnectionToLeagueAndReturnsItsRedirect(): void
    {
        $connection = $this->connection();
        $completer = $this->completer(static fn(Psr17Factory $factory): ResponseInterface => $factory->createResponse(302)->withHeader('Location', self::REDIRECT.'?code=c&state=s'));

        self::assertSame(self::REDIRECT.'?code=c&state=s', $completer->approve($this->summary(), $connection));
        self::assertTrue($this->authorization->isAuthorizationApproved());
        self::assertSame($connection->getId()->toRfc4122(), $this->authorization->getUser()?->getIdentifier());
    }

    public function testAnApprovalWithoutRedirectIsAServerFault(): void
    {
        $completer = $this->completer(static fn(Psr17Factory $factory): ResponseInterface => $factory->createResponse(200));

        $this->expectException(UnexpectedAuthorizationResponseException::class);

        $completer->approve($this->summary(), $this->connection());
    }

    /** Le refus : l'`access_denied` de league, sa redirection rendue telle quelle. */
    public function testADenialReturnsTheRedirectOfLeaguesAccessDenied(): void
    {
        $completer = $this->completer(static fn(): never => throw OAuthServerException::accessDenied('The user denied the request', self::REDIRECT.'?state=s'));

        $location = $completer->deny($this->summary());

        self::assertStringStartsWith(self::REDIRECT.'?state=s&', $location);
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        self::assertSame('access_denied', $query['error'] ?? null);
        self::assertFalse($this->authorization->isAuthorizationApproved());
        self::assertInstanceOf(DeniedRequestUser::class, $this->authorization->getUser());
    }

    /**
     * Ce que league peut rendre d'autre au refus, et qui ne doit jamais
     * partir vers le client : une autre erreur OAuth, même munie d'une
     * redirection, un `access_denied` sans redirection, aucune exception.
     *
     * @return iterable<string, array{OAuthServerException|null}>
     */
    public static function brokenDenials(): iterable
    {
        yield 'autre erreur, redirigée' => [OAuthServerException::invalidScope('nominative:read', self::REDIRECT.'?state=s')];
        yield 'erreur du serveur, redirigée' => [new OAuthServerException('Erreur.', 7, 'server_error', 500, null, self::REDIRECT)];
        yield 'access_denied sans redirection' => [OAuthServerException::accessDenied('The user denied the request')];
        yield 'aucune exception' => [null];
    }

    #[DataProvider('brokenDenials')]
    public function testADenialOtherThanARedirectedAccessDeniedIsAServerFault(?OAuthServerException $raised): void
    {
        $completer = $this->completer(static function (Psr17Factory $factory) use ($raised): ResponseInterface {
            if (null !== $raised) {
                throw $raised;
            }

            return $factory->createResponse(302)->withHeader('Location', self::REDIRECT.'?code=c');
        });

        try {
            $completer->deny($this->summary());
            self::fail('Ce refus ne doit pas rendre de redirection.');
        } catch (UnexpectedAuthorizationResponseException $fault) {
            // Une autre erreur que le refus est gardée pour le journal ; les
            // autres cas n'ont rien à garder.
            $kept = null !== $raised && 'access_denied' !== $raised->getErrorType() ? $raised : null;
            self::assertSame($kept, $fault->getPrevious());
        }
    }

    /**
     * @param callable(Psr17Factory): ResponseInterface $outcome ce que league rend, ou lève
     */
    private function completer(callable $outcome): LeagueAuthorizationCompleter
    {
        $factory = new Psr17Factory();
        $server = self::createStub(AuthorizationServer::class);
        $server->method('completeAuthorizationRequest')->willReturnCallback(static fn(): ResponseInterface => $outcome($factory));

        return new LeagueAuthorizationCompleter($server, $factory);
    }

    private function summary(): AuthorizationRequestSummary
    {
        return new AuthorizationRequestSummary(
            clientId: 'https://client.example.test/metadata.json',
            clientName: 'Client de test',
            redirectUri: self::REDIRECT,
            redirectHost: 'localhost',
            scope: 'nominative:read',
            resource: 'https://api.example.test/api/mcp',
            authorizationRequest: $this->authorization,
        );
    }

    private function connection(): McpConnection
    {
        return McpConnection::grant(
            new CpgUser('trusted', 'hashed-password'),
            McpClient::register('https://client.example.test/metadata.json', 'Client de test', ['http://localhost/callback'], new DateTimeImmutable()),
            'nominative:read',
            new DateTimeImmutable(),
        );
    }
}
