<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Infrastructure\League;

use App\Mcp\Connection\Application\FailSafeAudit;
use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Infrastructure\League\LoopbackAwareAuthCodeGrant;
use App\Mcp\Connection\Infrastructure\League\OAuthClient;
use App\Mcp\Connection\Infrastructure\League\OAuthEncryptionKey;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Tests\Mcp\Connection\Fixtures\ClaudeCodeMetadata;
use App\Tests\Support\TestKeys;
use DateInterval;
use Defuse\Crypto\Crypto;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\ResponseTypes\BearerTokenResponse;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Le refus d'une ligne `cimd` que l'admission n'a pas reconnue (#651) et son
 * audit : une panne de l'audit ne change jamais l'issue (la règle de #725,
 * FailSafeAudit) — league rend toujours `invalid_client`, sans redirection.
 */
final class LoopbackAwareAuthCodeGrantTest extends TestCase
{
    public function testAnAuditFailureLeavesTheRefusalOfAStoredCimdRowUnchanged(): void
    {
        $audit = $this->createMock(SecurityAuditLoggerInterface::class);
        $audit->expects(self::once())->method('mcpClientRowNotAdmitted')->with('claude.ai')
            ->willThrowException(new RuntimeException('secret détail du pilote'));
        $handler = new TestHandler();

        try {
            $this->grant($audit, new Logger('app', [$handler]))->validateAuthorizationRequest($this->request());
            self::fail('La ligne `cimd` non admise aurait dû être refusée.');
        } catch (OAuthServerException $exception) {
            self::assertSame('invalid_client', $exception->getErrorType());
            self::assertFalse($exception->hasRedirect());
        }

        // La panne sort en UN seul enregistrement `critical`, au message constant
        // du garde et à la classe seule (jamais le message du pilote).
        $records = $handler->getRecords();
        self::assertCount(1, $records);
        self::assertSame(Level::Critical, $records[0]->level);
        self::assertSame('Refused stored CIMD client row not audited.', $records[0]->message);
        self::assertSame(['exceptionClass' => RuntimeException::class], $records[0]->context);
        self::assertStringNotContainsString('secret', $records[0]->message);
    }

    /**
     * Une ligne `cimd` dont le `client_id` n'a plus la forme d'un identifiant
     * CIMD (écrite à la main, ou avant une règle plus stricte) : l'audit reçoit
     * exactement `unreadable`, jamais l'identifiant ni une valeur vide.
     */
    public function testAStoredCimdRowWhoseClientIdIsUnreadableIsAuditedAsUnreadable(): void
    {
        $row = ClaudeCodeMetadata::storedRowWhoseClientIdIs('pas-un-identifiant-cimd');
        $audit = $this->createMock(SecurityAuditLoggerInterface::class);
        // L'hôte reçu est relevé puis comparé à la fin : un choix de lisibilité
        // (un message d'échec explicite), non une nécessité. Sous `once()`, un
        // `with()` serait aussi vérifié : FailSafeAudit avale bien l'échec levé
        // dans l'écriture de l'audit, mais PHPUnit 13.4.6 le mémorise
        // (`Parameters::apply()`) et `Matcher::verify()` le relance en fin de test.
        // Ce n'est pas propre à `once()` : `Matcher::verify()` relit les paramètres
        // sous toute attente sauf `any()`, `never()` et `atMost()` (`exactly()`,
        // `atLeast()` et `atLeastOnce()` les relisent aussi).
        $received = [];
        $audit->expects(self::once())->method('mcpClientRowNotAdmitted')
            ->willReturnCallback(static function (string $clientHost) use (&$received): void {
                $received[] = $clientHost;
            });

        try {
            $this->grant($audit, new Logger('app', [new TestHandler()]), $row)->validateAuthorizationRequest($this->request());
            self::fail('La ligne `cimd` non admise aurait dû être refusée.');
        } catch (OAuthServerException $exception) {
            self::assertSame('invalid_client', $exception->getErrorType());
        }

        self::assertSame(['unreadable'], $received);
    }

    /**
     * Une redirection retirée et une charge mal typée (`expire_time` en chaîne) :
     * le code n'est ni jugé échu ni consommé sur une charge dont le contrôle ne
     * peut rien dire, il est refusé comme un code illisible (message de league,
     * « Authorization code malformed ») et jamais échangé.
     */
    public function testACodeWithAMistypedPayloadAndARemovedRedirectIsRefusedAsMalformed(): void
    {
        $codes = $this->createMock(AuthCodeRepositoryInterface::class);
        // Ni le repli ni league ne doivent atteindre le dépôt : refusé avant tout échange.
        $codes->expects(self::never())->method('isAuthCodeRevoked');
        $grant = $this->grant(self::createStub(SecurityAuditLoggerInterface::class), new Logger('app', [new TestHandler()]), null, $codes);
        $code = Crypto::encrypt(json_encode([
            'client_id' => ClaudeCodeMetadata::CLIENT_ID,
            'redirect_uri' => 'https://client.example.test/callback',
            'auth_code_id' => 'identifiant-du-code',
            'expire_time' => (string) (time() + 600),
            'user_id' => '0199f0a4-5c3e-7a2b-8d1e-2f3a4b5c6d7e',
            'scopes' => [],
        ], \JSON_THROW_ON_ERROR), OAuthEncryptionKey::fromSecret(self::keyMaterial()));
        $request = new ServerRequest('POST', 'http://localhost/api/oauth/token')->withParsedBody([
            'grant_type' => 'authorization_code',
            'client_id' => ClaudeCodeMetadata::CLIENT_ID,
            'redirect_uri' => 'https://client.example.test/callback',
            'code' => $code,
            'code_verifier' => str_repeat('a', 64),
        ]);

        try {
            $grant->respondToAccessTokenRequest($request, new BearerTokenResponse(), new DateInterval('PT1H'));
            self::fail('Le code à charge mal typée aurait dû être refusé.');
        } catch (OAuthServerException $exception) {
            self::assertSame('invalid_request', $exception->getErrorType());
            self::assertStringContainsString('Authorization code malformed', $exception->getHint() ?? '');
        }
    }

    private static function keyMaterial(): string
    {
        return TestKeys::material('grant-test');
    }

    private function grant(SecurityAuditLoggerInterface $audit, Logger $logger, ?McpClient $stored = null, ?AuthCodeRepositoryInterface $codes = null): LoopbackAwareAuthCodeGrant
    {
        $row = new OAuthClient($stored ?? ClaudeCodeMetadata::storedRow());
        $clients = self::createStub(ClientRepositoryInterface::class);
        $clients->method('getClientEntity')->willReturn($row);

        $grant = new LoopbackAwareAuthCodeGrant(
            $codes ?? self::createStub(AuthCodeRepositoryInterface::class),
            self::createStub(RefreshTokenRepositoryInterface::class),
            new DateInterval('PT1M'),
            $audit,
            new FailSafeAudit($logger),
        );
        $grant->setClientRepository($clients);
        $grant->setEncryptionKey(OAuthEncryptionKey::fromSecret(self::keyMaterial()));

        return $grant;
    }

    private function request(): ServerRequest
    {
        return new ServerRequest('GET', 'http://localhost/api/oauth/authorize')->withQueryParams([
            'response_type' => 'code',
            'client_id' => ClaudeCodeMetadata::CLIENT_ID,
            'redirect_uri' => 'http://localhost:53124/callback',
            'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            'code_challenge_method' => 'S256',
        ]);
    }
}
