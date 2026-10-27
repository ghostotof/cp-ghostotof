<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Infrastructure\League;

use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\ConnectionReference;
use App\Mcp\Connection\Infrastructure\League\GrantPayloadReader;
use App\Mcp\Connection\Infrastructure\League\LeagueGrantRefusalAudit;
use App\Mcp\Connection\Infrastructure\League\OAuthEncryptionKey;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Tests\Support\TestKeys;
use Defuse\Crypto\Crypto;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Exception\UniqueTokenIdentifierConstraintViolationException;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Ce que l'audit d'un refus tient pour un refus (#725, relecture de l'étape
 * 07) : un refus du client, de statut 4xx. Le `catch` de TokenEndpoint
 * attrape aussi les OAuthServerException de statut 5xx que league lève
 * **après** une validation réussie — une collision d'identifiant à
 * l'écriture d'un jeton, une panne — : un code valide y était audité
 * `unrecognised`, une fausse alerte qui désignait le compte légitime.
 *
 * L'artefact est authentique (chiffré sous la clé du serveur) et sa
 * connexion existe : seul le statut du refus décide ici.
 */
final class LeagueGrantRefusalAuditTest extends TestCase
{
    private const string CLIENT_ID = 'https://client.example.test/metadata.json';

    /**
     * @return iterable<string, array{OAuthServerException}>
     */
    public static function serverFaults(): iterable
    {
        yield 'collision d\'identifiant à l\'écriture' => [UniqueTokenIdentifierConstraintViolationException::create()];
        yield 'panne du serveur' => [OAuthServerException::serverError('panne')];
    }

    #[DataProvider('serverFaults')]
    public function testAServerFaultIsNotARefusalOfTheGrant(OAuthServerException $fault): void
    {
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('mcpGrantRefused');

        $this->audit($auditLogger)->record($this->exchangeOfAnAuthenticCode(), $fault);
    }

    /** Le témoin : le même artefact, refusé par le client, est audité. */
    public function testAClientRefusalOfTheSameGrantIsAudited(): void
    {
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('mcpGrantRefused')
            ->with(self::isInstanceOf(ConnectionReference::class), 'authorization_code', 'verifier-mismatch', self::CLIENT_ID);

        $this->audit($auditLogger)->record($this->exchangeOfAnAuthenticCode(), OAuthServerException::invalidGrant('Failed to verify `code_verifier`.'));
    }

    private function audit(SecurityAuditLoggerInterface $auditLogger): LeagueGrantRefusalAudit
    {
        $connections = $this->createStub(McpConnectionRepositoryInterface::class);
        $connections->method('referenceOf')->willReturnCallback(
            static fn(Uuid $id): ConnectionReference => new ConnectionReference($id, Uuid::v7()->toRfc4122(), self::CLIENT_ID),
        );

        return new LeagueGrantRefusalAudit(new GrantPayloadReader(new OAuthEncryptionKey(TestKeys::material())), $connections, $auditLogger);
    }

    private function exchangeOfAnAuthenticCode(): ServerRequest
    {
        $code = Crypto::encrypt(
            json_encode(['auth_code_id' => 'abc', 'user_id' => Uuid::v7()->toRfc4122(), 'client_id' => self::CLIENT_ID], \JSON_THROW_ON_ERROR),
            OAuthEncryptionKey::fromSecret(TestKeys::material()),
        );

        return new ServerRequest('POST', '/api/oauth/token')->withParsedBody([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => self::CLIENT_ID,
        ]);
    }
}
