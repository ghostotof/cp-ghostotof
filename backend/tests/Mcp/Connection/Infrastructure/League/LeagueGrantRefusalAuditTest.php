<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Infrastructure\League;

use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\AllowedClientDomains;
use App\Mcp\Connection\Domain\ValueObject\ClientRegistration;
use App\Mcp\Connection\Domain\ValueObject\ConnectionReference;
use App\Mcp\Connection\Infrastructure\League\GrantPayloadReader;
use App\Mcp\Connection\Infrastructure\League\LeagueGrantRefusalAudit;
use App\Mcp\Connection\Infrastructure\League\OAuthEncryptionKey;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Shared\Infrastructure\Security\EmptyApplicationSecretException;
use App\Tests\Mcp\Connection\Application\McpClientRepositoryFake;
use App\Tests\Mcp\Connection\Fixtures\ClaudeCodeMetadata;
use App\Tests\Mcp\Connection\RegisteredMcpClient;
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

    /**
     * Le `client_id` du corps désigne une ligne `preregistered` qui masque le
     * client d'un domaine vérifié (règle D-C) et l'artefact est authentique :
     * la ligne, de cause dédiée, désigne la connexion de l'artefact et le
     * client présenté.
     */
    public function testAShadowingPreregisteredRowIsAuditedOnTheConnectionOfTheArtifact(): void
    {
        // La connexion que l'artefact désigne : la ligne doit porter celle-là, pas une autre.
        $connectionId = Uuid::v7();
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('mcpGrantRefused')
            ->with(
                self::callback(static fn (ConnectionReference $connection): bool => ClaudeCodeMetadata::CLIENT_ID === $connection->clientId() && $connection->connectionId()->equals($connectionId)),
                'authorization_code',
                'preregistered-row-shadows-verified-domain',
                ClaudeCodeMetadata::CLIENT_ID,
            );
        $clients = new McpClientRepositoryFake(RegisteredMcpClient::of(ClaudeCodeMetadata::CLIENT_ID, 'Faux Claude Code', ['http://localhost/callback']), null);

        // L'artefact est celui d'une connexion du client masquant lui-même.
        $request = $this->exchangePresentedBy(ClaudeCodeMetadata::CLIENT_ID, $connectionId);
        $this->auditOfClients($clients, $auditLogger, connectionClientId: ClaudeCodeMetadata::CLIENT_ID)->record($request, OAuthServerException::invalidClient($request));
    }

    /** Client croisé : l'artefact est celui du client A, la ligne masquante présentée est B ; `clientId` est A, `presentingClientId` B. */
    public function testACrossedClientIsAuditedWithBothClients(): void
    {
        $connectionId = Uuid::v7();
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('mcpGrantRefused')
            ->with(
                // La connexion est celle de l'artefact, le client A.
                self::callback(static fn (ConnectionReference $connection): bool => self::CLIENT_ID === $connection->clientId() && $connection->connectionId()->equals($connectionId)),
                'authorization_code',
                'preregistered-row-shadows-verified-domain',
                // Le client présenté est la ligne masquante B.
                ClaudeCodeMetadata::CLIENT_ID,
            );
        $clients = new McpClientRepositoryFake(RegisteredMcpClient::of(ClaudeCodeMetadata::CLIENT_ID, 'Faux Claude Code', ['http://localhost/callback']), null);

        $request = $this->exchangePresentedBy(ClaudeCodeMetadata::CLIENT_ID, $connectionId);
        $this->auditOfClients($clients, $auditLogger, connectionClientId: self::CLIENT_ID)->record($request, OAuthServerException::invalidClient($request));
    }

    /** Une ligne `preregistered` hors de tout domaine vérifié ne masque rien : aucune ligne. */
    public function testAPreregisteredRowOutsideAVerifiedDomainIsNotAudited(): void
    {
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('mcpGrantRefused');
        $clients = new McpClientRepositoryFake(RegisteredMcpClient::of('https://client.example.test/other.json', 'Autre client', ['http://localhost/callback']), null);

        $request = $this->exchangePresentedBy('https://client.example.test/other.json');
        $this->auditOfClients($clients, $auditLogger)->record($request, OAuthServerException::invalidClient($request));
    }

    /** La ligne `cimd` du même identifiant sur un domaine vérifié est le client servi, pas une ligne masquante : aucune ligne. */
    public function testACimdRowOfAVerifiedDomainIsNotAudited(): void
    {
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('mcpGrantRefused');
        $clients = new McpClientRepositoryFake(ClaudeCodeMetadata::storedRow(), null);
        self::assertSame(ClientRegistration::Cimd, $clients->registrationOf(ClaudeCodeMetadata::CLIENT_ID));

        $request = $this->exchangePresentedBy(ClaudeCodeMetadata::CLIENT_ID);
        $this->auditOfClients($clients, $auditLogger)->record($request, OAuthServerException::invalidClient($request));
    }

    /**
     * Un `client_id` hors domaine vérifié est tranché en mémoire (#652) :
     * league ne déchiffre rien sur `invalid_client` et l'audit non plus. Le
     * second élément dit si le dépôt porte une ligne pour cet identifiant :
     * une ligne ordinaire enregistrée, ou aucune ligne du tout (un identifiant
     * inconnu, y compris de la forme d'une URL de métadonnées). Le lecteur de
     * charge porte ici une clé que son secret vide rend inutilisable — tout
     * déchiffrement lèverait EmptyApplicationSecretException —, et le fake
     * compte les lectures SQL.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function clientIdsOutsideAVerifiedDomain(): iterable
    {
        yield 'URL de métadonnées d\'un hôte non vérifié, ligne ordinaire enregistrée' => ['https://client.example.test/unknown.json', true];
        yield 'domaine non vérifié, ligne ordinaire enregistrée' => [self::CLIENT_ID, true];
        yield 'identifiant ordinaire, ligne enregistrée' => ['plain-client', true];
        yield 'port, que CimdClientId ne reconnaît pas, ligne enregistrée' => ['https://claude.ai:443/oauth/claude-code-client-metadata', true];
        yield 'identifiant inconnu, aucune ligne' => ['unknown-client', false];
        yield 'URL de métadonnées d\'un hôte non vérifié, aucune ligne' => ['https://client.example.test/unknown.json', false];
        yield 'domaine non vérifié, aucune ligne' => [self::CLIENT_ID, false];
    }

    #[DataProvider('clientIdsOutsideAVerifiedDomain')]
    public function testAClientOutsideAVerifiedDomainDecryptsNothingAndReadsNoRow(string $presentedClientId, bool $registered): void
    {
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('mcpGrantRefused');
        $clients = new McpClientRepositoryFake(
            $registered ? RegisteredMcpClient::of($presentedClientId, 'Client ordinaire', ['http://localhost/callback']) : null,
            null,
        );

        $request = $this->exchangePresentedBy($presentedClientId);
        // Un secret vide : tout déchiffrement lèverait EmptyApplicationSecretException.
        $this->auditOfClients($clients, $auditLogger, new OAuthEncryptionKey(''))->record($request, OAuthServerException::invalidClient($request));

        self::assertSame(0, $clients->registrationReads);
    }

    /** Le témoin du précédent : le lecteur de secret vide lève bien dès qu'un refus autre que `invalid_client` déchiffre. */
    public function testTheEmptySecretWitnessFailsWhenTheArtifactIsDecrypted(): void
    {
        $request = $this->exchangePresentedBy(self::CLIENT_ID);
        $audit = $this->auditOfClients(new McpClientRepositoryFake(null, null), self::createStub(SecurityAuditLoggerInterface::class), new OAuthEncryptionKey(''));

        $this->expectException(EmptyApplicationSecretException::class);

        $audit->record($request, OAuthServerException::invalidGrant('Failed to verify `code_verifier`.'));
    }

    /**
     * Un `client_id` qui n'est pas de la forme du domaine (octets non UTF-8,
     * NUL) n'atteint jamais PostgreSQL, qui le rejetterait : aucune lecture,
     * aucune ligne. Il n'est pas d'un domaine vérifié (CimdClientId::tryFrom()
     * passe d'abord par McpClientId::fromString()).
     *
     * @return iterable<string, array{string}>
     */
    public static function malformedClientIds(): iterable
    {
        yield 'octets non UTF-8' => ["\xFF\xFE"];
        yield 'octet nul' => ["https://claude.ai/oauth/\0x"];
        yield 'domaine vérifié suivi d\'octets non UTF-8' => ["https://claude.ai/oauth/\xFF\xFE"];
    }

    #[DataProvider('malformedClientIds')]
    public function testAMalformedClientIdNeverReachesTheDatabase(string $presentedClientId): void
    {
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('mcpGrantRefused');
        $clients = new McpClientRepositoryFake(null, null);

        $request = $this->exchangePresentedBy($presentedClientId);
        $this->auditOfClients($clients, $auditLogger)->record($request, OAuthServerException::invalidClient($request));

        self::assertSame(0, $clients->registrationReads);
    }

    /**
     * Un identifiant d'un domaine vérifié, sans aucune ligne, avec un artefact
     * authentique : le client est refusé par league (`invalid_client`), mais
     * aucune ligne ne le masque. La nature du client est lue une fois, rien
     * n'est audité.
     */
    public function testAVerifiedDomainClientWithoutARowIsReadOnceAndNotAudited(): void
    {
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('mcpGrantRefused');
        $clients = new McpClientRepositoryFake(null, null);

        $request = $this->exchangePresentedBy(ClaudeCodeMetadata::CLIENT_ID);
        $this->auditOfClients($clients, $auditLogger)->record($request, OAuthServerException::invalidClient($request));

        self::assertSame(1, $clients->registrationReads, 'Le client est d\'un domaine vérifié et l\'artefact authentique : la ligne est lue, une fois.');
    }

    /** Une faute serveur reste exclue en tête, même sur une ligne masquante : aucune lecture. */
    public function testAServerFaultOnAShadowingRowReadsNothing(): void
    {
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('mcpGrantRefused');
        $clients = new McpClientRepositoryFake(RegisteredMcpClient::of(ClaudeCodeMetadata::CLIENT_ID, 'Faux Claude Code', ['http://localhost/callback']), null);

        $this->auditOfClients($clients, $auditLogger)->record($this->exchangePresentedBy(ClaudeCodeMetadata::CLIENT_ID), OAuthServerException::serverError('panne'));

        self::assertSame(0, $clients->registrationReads);
    }

    /** Un artefact forgé sur une ligne masquante : le client passe le garde en mémoire, mais l'artefact, qui ne se déchiffre pas, arrête tout avant la lecture de la ligne — aucune connexion n'est désignée. */
    public function testAForgedArtifactOnAShadowingRowIsNotAudited(): void
    {
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('mcpGrantRefused');
        $clients = new McpClientRepositoryFake(RegisteredMcpClient::of(ClaudeCodeMetadata::CLIENT_ID, 'Faux Claude Code', ['http://localhost/callback']), null);
        $request = $this->exchangePresentedBy(ClaudeCodeMetadata::CLIENT_ID)->withParsedBody(['grant_type' => 'authorization_code', 'code' => 'forged', 'client_id' => ClaudeCodeMetadata::CLIENT_ID]);

        $this->auditOfClients($clients, $auditLogger)->record($request, OAuthServerException::invalidClient($request));

        self::assertSame(0, $clients->registrationReads, 'Sans artefact authentique, la ligne n\'est pas lue.');
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
        return new LeagueGrantRefusalAudit(new GrantPayloadReader(new OAuthEncryptionKey(TestKeys::material())), $connections, new McpClientRepositoryFake(null, null), AllowedClientDomains::fromList(['claude.ai']), $auditLogger);
    }

    /** Un dépôt de clients sur mesure, et, au besoin, une clé de chiffrement autre que celle du code. */
    private function auditOfClients(McpClientRepositoryFake $clients, SecurityAuditLoggerInterface $auditLogger, ?OAuthEncryptionKey $key = null, string $connectionClientId = self::CLIENT_ID): LeagueGrantRefusalAudit
    {
        $connections = self::createStub(McpConnectionRepositoryInterface::class);
        $connections->method('referenceOf')->willReturnCallback(
            static fn (Uuid $id): ConnectionReference => new ConnectionReference($id, Uuid::v7()->toRfc4122(), $connectionClientId),
        );

        return new LeagueGrantRefusalAudit(new GrantPayloadReader($key ?? new OAuthEncryptionKey(TestKeys::material())), $connections, $clients, AllowedClientDomains::fromList(['claude.ai']), $auditLogger);
    }

    /** Le code authentique du client A (CLIENT_ID), présenté par `$presentedClientId` ; `$connectionId` est celle qu'il désigne (neuve par défaut). */
    private function exchangePresentedBy(string $presentedClientId, ?Uuid $connectionId = null): ServerRequest
    {
        return $this->exchangeOfAnAuthenticCode()->withParsedBody([
            'grant_type' => 'authorization_code',
            'code' => $this->authenticCode($connectionId),
            'client_id' => $presentedClientId,
        ]);
    }

    /** Un code chiffré sous la clé du serveur : authentique, pour la connexion donnée, ou une connexion au `user_id` neuf. */
    private function authenticCode(?Uuid $connectionId = null): string
    {
        return Crypto::encrypt(
            json_encode(['auth_code_id' => 'abc', 'user_id' => ($connectionId ?? Uuid::v7())->toRfc4122(), 'client_id' => self::CLIENT_ID], \JSON_THROW_ON_ERROR),
            OAuthEncryptionKey::fromSecret(TestKeys::material()),
        );
    }

    private function exchangeOfAnAuthenticCode(): ServerRequest
    {
        return new ServerRequest('POST', '/api/oauth/token')->withParsedBody([
            'grant_type' => 'authorization_code',
            'code' => $this->authenticCode(),
            'client_id' => self::CLIENT_ID,
        ]);
    }
}
