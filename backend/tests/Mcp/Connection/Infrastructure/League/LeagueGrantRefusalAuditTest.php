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

    /** L'indication de league pour un code révoqué, mot pour mot. */
    private const string REVOKED_CODE_HINT = 'Authorization code has been revoked';

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

    /**
     * Le rejeu non prouvé (#726) : un code rejoué sans preuve PKCE reçoit le
     * refus d'un code révoqué, mot pour mot, que l'indication seule classerait
     * `code-refused` ; TokenEndpoint, qui sait qu'il s'agit d'un rejeu non
     * prouvé, l'audite `replay-unproven` sur la connexion que la compromission
     * désigne.
     */
    public function testAnUnprovenReplayIsAuditedOnTheKnownConnection(): void
    {
        $connectionId = Uuid::v7();
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('mcpGrantRefused')
            ->with(
                self::callback(static fn (ConnectionReference $connection): bool => $connection->connectionId()->equals($connectionId)),
                'authorization_code',
                'replay-unproven',
                self::CLIENT_ID,
            );

        $this->audit($auditLogger)->recordUnprovenReplay($this->exchangeOfAnAuthenticCode(), $connectionId);
    }

    /**
     * Le code n'est pas redéchiffré (#726, CR4) : un code que le serveur ne
     * lit pas — record() n'écrirait rien — laisse la ligne du rejeu non
     * prouvé intacte, sur la connexion passée. La seule requête est la
     * lecture de sa référence.
     */
    public function testAnUnprovenReplayDecryptsNothing(): void
    {
        $connectionId = Uuid::v7();
        $request = $this->exchangeOfAnAuthenticCode()->withParsedBody([
            'grant_type' => 'authorization_code',
            'code' => 'illisible',
            'client_id' => self::CLIENT_ID,
        ]);
        $connections = $this->createMock(McpConnectionRepositoryInterface::class);
        $connections->expects(self::once())->method('referenceOf')
            ->with(self::callback(static fn (Uuid $id): bool => $id->equals($connectionId)))
            ->willReturn(new ConnectionReference($connectionId, Uuid::v7()->toRfc4122(), self::CLIENT_ID));
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('mcpGrantRefused')
            ->with(self::isInstanceOf(ConnectionReference::class), 'authorization_code', 'replay-unproven', self::CLIENT_ID);

        $this->auditWith($connections, $auditLogger)->recordUnprovenReplay($request, $connectionId);

        // Le témoin : le même corps, refusé par league, n'est pas audité —
        // record() déchiffre, et ne lit rien.
        $witnessLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $witnessLogger->expects(self::never())->method('mcpGrantRefused');
        $this->audit($witnessLogger)->record($request, OAuthServerException::invalidGrant(self::REVOKED_CODE_HINT));
    }

    /** Un corps sans `client_id` en chaîne ne nomme pas qui a présenté le code : aucune ligne, aucune requête. */
    public function testAnUnprovenReplayWithoutAPresentingClientIsNotAudited(): void
    {
        $request = $this->exchangeOfAnAuthenticCode()->withParsedBody(['grant_type' => 'authorization_code', 'code' => 'c']);
        $connections = $this->createMock(McpConnectionRepositoryInterface::class);
        $connections->expects(self::never())->method('referenceOf');
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('mcpGrantRefused');

        $this->auditWith($connections, $auditLogger)->recordUnprovenReplay($request, Uuid::v7());
    }

    /** Une connexion qui n'est plus en base ne désigne personne : aucune ligne. */
    public function testAnUnprovenReplayOnAVanishedConnectionIsNotAudited(): void
    {
        $connections = self::createStub(McpConnectionRepositoryInterface::class);
        $connections->method('referenceOf')->willReturn(null);
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('mcpGrantRefused');

        $this->auditWith($connections, $auditLogger)->recordUnprovenReplay($this->exchangeOfAnAuthenticCode(), Uuid::v7());
    }

    /** Le classement par l'indication de league : le même refus, par record(), reste `code-refused`. */
    public function testRecordClassifiesTheRefusalByLeaguesHint(): void
    {
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('mcpGrantRefused')
            ->with(self::isInstanceOf(ConnectionReference::class), 'authorization_code', 'code-refused', self::CLIENT_ID);

        $this->audit($auditLogger)->record($this->exchangeOfAnAuthenticCode(), OAuthServerException::invalidGrant(self::REVOKED_CODE_HINT));
    }

    private function audit(SecurityAuditLoggerInterface $auditLogger): LeagueGrantRefusalAudit
    {
        $connections = self::createStub(McpConnectionRepositoryInterface::class);
        $connections->method('referenceOf')->willReturnCallback(
            static fn (Uuid $id): ConnectionReference => new ConnectionReference($id, Uuid::v7()->toRfc4122(), self::CLIENT_ID),
        );

        return $this->auditWith($connections, $auditLogger);
    }

    private function auditWith(McpConnectionRepositoryInterface $connections, SecurityAuditLoggerInterface $auditLogger): LeagueGrantRefusalAudit
    {
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
