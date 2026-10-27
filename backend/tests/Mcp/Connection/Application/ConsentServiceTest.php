<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Application;

use App\Mcp\Connection\Application\AuthenticationInstantInterface;
use App\Mcp\Connection\Application\AuthorizationCompleterInterface;
use App\Mcp\Connection\Application\AuthorizationRequestSummary;
use App\Mcp\Connection\Application\AuthorizationRequestValidatorInterface;
use App\Mcp\Connection\Application\ConsentDecision;
use App\Mcp\Connection\Application\ConsentService;
use App\Mcp\Connection\Application\OAuthEndpoints;
use App\Mcp\Connection\Application\TransactionInterface;
use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\Exception\AuthorizationRequestRejectedException;
use App\Mcp\Connection\Domain\Exception\ReauthenticationRequiredException;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\ConnectionReference;
use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use App\Mcp\Connection\Domain\ValueObject\RevokedConnection;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Shared\Domain\Security\McpConnectionReference;
use App\Tests\Mcp\Connection\RegisteredMcpClient;
use Closure;
use DateInterval;
use DateTimeImmutable;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * ConsentService (#647) hors HTTP, sur des doublures qui consignent leurs
 * effets dans l'ordre : ce que le test fonctionnel ne peut pas provoquer — un
 * COMMIT refusé, un échec de league dans la transaction, un client retiré
 * entre la validation et l'écriture — et l'ordre des effets : l'audit
 * **après** la transaction, jamais pour un refus ni pour un échec.
 */
final class ConsentServiceTest extends TestCase
{
    private const string CLIENT_ID = 'https://client.example.test/metadata.json';
    private const string REDIRECT = 'http://localhost:49152/callback';
    private const string API_BASE = 'https://api.example.test';
    private const string GRANTED_AT = '2026-10-23T10:00:00+00:00';

    private McpClient $client;
    private CpgUser $account;

    /** @var list<string> les effets des doublures, dans l'ordre où ils se produisent */
    private array $effects = [];

    /** La connexion que le dépôt a reçue. */
    private ?McpConnection $added = null;

    /** @var array{string, Uuid, string}|null le compte, la connexion et le client que la ligne d'audit désigne */
    private ?array $audited = null;

    /**
     * Ce que `revokeOtherActiveOf()` rendrait si l'accord l'appelait — il ne
     * l'appelle plus (#726, D2) : de quoi voir une révocation réintroduite
     * et son audit.
     *
     * @var list<RevokedConnection>
     */
    private array $superseded = [];

    /** @var array{McpConnection, RevocationCause, DateTimeImmutable}|null les arguments du remplacement */
    private ?array $supersededWith = null;

    /** @var list<array{McpConnectionReference, string}> les arguments de chaque ligne `mcp-connection-revoked` */
    private array $revokedAudits = [];

    /** La requête que le validateur a reçue. */
    private ?ServerRequestInterface $validated = null;

    /** Ce que le validateur lève au lieu de valider. */
    private ?Throwable $validatorRefusal = null;

    /** Ce que league lève à l'accord, dans la transaction. */
    private ?Throwable $approvalFailure = null;

    private bool $commitRefused = false;

    private bool $clientRegistered = true;

    /**
     * L'instant d'authentification que le port rend : par défaut celui de
     * l'horloge (une connexion fraîche) ; null, un jeton dont l'instant ne
     * peut être établi.
     */
    private ?DateTimeImmutable $authenticatedAt;

    protected function setUp(): void
    {
        $this->client = RegisteredMcpClient::of(self::CLIENT_ID, 'Client de test', ['http://localhost/callback']);
        $this->account = new CpgUser('trusted', 'hashed-password');
        $this->account->setRoles([CpgUser::ROLE_TRUSTED]);
        $this->authenticatedAt = new DateTimeImmutable(self::GRANTED_AT);
    }

    /**
     * Le cas nominal : la connexion du compte, au client, au scope retenu et
     * à l'instant de l'horloge ; écrite puis complétée dans la transaction ;
     * l'audit ensuite, avec l'UUID de cette connexion ; `iss` ajouté.
     */
    public function testAnApprovalWritesTheConnectionInTheTransactionThenAudits(): void
    {
        $location = $this->service()->decide($this->decision(true), $this->account);

        self::assertSame(self::REDIRECT.'?code=c&state=s&'.http_build_query(['iss' => self::API_BASE.'/api/oauth']), $location);
        self::assertSame(['begin', 'add', 'approve', 'commit', 'audit'], $this->effects);
        self::assertInstanceOf(McpConnection::class, $this->added);
        self::assertSame($this->account, $this->added->account());
        self::assertSame($this->client, $this->added->client());
        self::assertSame('nominative:read', $this->added->scope());
        self::assertSame((new DateTimeImmutable(self::GRANTED_AT))->getTimestamp(), $this->added->grantedAt()->getTimestamp());
        self::assertNotNull($this->audited);
        [$accountId, $connectionId, $clientId] = $this->audited;
        self::assertSame($this->account->accountId(), $accountId);
        self::assertTrue($this->added->getId()->equals($connectionId));
        self::assertSame(self::CLIENT_ID, $clientId);
    }

    /**
     * Un COMMIT refusé : l'exception remonte telle quelle, aucune ligne
     * d'audit pour un accord que la transaction annulée n'a jamais écrit.
     */
    public function testARefusedCommitWritesNoAuditLine(): void
    {
        $this->commitRefused = true;

        try {
            $this->service()->decide($this->decision(true), $this->account);
            self::fail('Le COMMIT refusé doit remonter.');
        } catch (RuntimeException $failure) {
            self::assertSame('COMMIT refusé', $failure->getMessage());
        }

        self::assertSame(['begin', 'add', 'approve'], $this->effects);
        self::assertNull($this->audited);
        self::assertSame([], $this->revokedAudits);
    }

    /** Un échec de league dans la transaction : il remonte, rien de journalisé non plus. */
    public function testAFailureToIssueTheCodeWritesNoAuditLine(): void
    {
        $this->approvalFailure = OAuthServerException::serverError('Could not create a unique identifier.');

        try {
            $this->service()->decide($this->decision(true), $this->account);
            self::fail('L\'échec de league doit remonter.');
        } catch (OAuthServerException $failure) {
            self::assertSame($this->approvalFailure, $failure);
        }

        self::assertSame(['begin', 'add', 'approve'], $this->effects);
        self::assertNull($this->audited);
        self::assertSame([], $this->revokedAudits);
    }

    /** Le refus : la redirection de league, `iss` ajouté ; ni transaction, ni connexion, ni audit. */
    public function testADenialCreatesNothingAndAuditsNothing(): void
    {
        $location = $this->service()->decide($this->decision(false), $this->account);

        self::assertSame(self::REDIRECT.'?state=s&error=access_denied&'.http_build_query(['iss' => self::API_BASE.'/api/oauth']), $location);
        self::assertSame(['deny'], $this->effects);
    }

    /**
     * L'approbation ne révoque rien (#726, D2) : aucun appel à
     * `revokeOtherActiveOf()`, aucune ligne `mcp-connection-revoked` — même
     * quand le dépôt aurait des connexions à remplacer. Le remplacement de
     * l'accord précédent attend le premier échange du nouveau code, au point
     * de jeton : un accord abandonné laisse l'ancien vivant.
     */
    public function testAnApprovalRevokesNothing(): void
    {
        $this->superseded = [$this->revokedConnection()];

        $this->service()->decide($this->decision(true), $this->account);

        self::assertSame(['begin', 'add', 'approve', 'commit', 'audit'], $this->effects);
        self::assertNull($this->supersededWith);
        self::assertSame([], $this->revokedAudits);
    }

    /**
     * Le refus ne remplace rien : aucune révocation demandée au dépôt, aucune
     * ligne `mcp-connection-revoked` — dire non à une demande ne coupe pas la
     * connexion que la personne a déjà accordée.
     */
    public function testADenialRevokesNothing(): void
    {
        $this->superseded = [$this->revokedConnection()];

        $this->service()->decide($this->decision(false), $this->account);

        self::assertSame(['deny'], $this->effects);
        self::assertNull($this->supersededWith);
        self::assertSame([], $this->revokedAudits);
    }

    /**
     * Le client retiré entre la validation et l'écriture : la demande n'est
     * plus valable (400 typé), rien n'est écrit ni journalisé.
     */
    public function testAClientRemovedSinceTheValidationIsRejected(): void
    {
        $this->clientRegistered = false;

        try {
            $this->service()->decide($this->decision(true), $this->account);
            self::fail('Un client retiré doit être refusé.');
        } catch (AuthorizationRequestRejectedException $rejection) {
            self::assertSame(400, $rejection->getStatus());
            self::assertSame('/errors/authorization-request-rejected', $rejection->getType());
        }

        self::assertSame([], $this->effects);
    }

    /**
     * Tout refus du validateur devient le 400 typé, le refus de league gardé
     * en `previous` — au résumé comme à la décision, et rien n'est écrit.
     */
    public function testAValidatorRefusalBecomesTheRejectionWithTheRefusalAsPrevious(): void
    {
        $refusal = OAuthServerException::invalidRequest('redirect_uri');
        $this->validatorRefusal = $refusal;
        $calls = [
            'résumé' => fn(): mixed => $this->service()->summarize((new Psr17Factory())->createServerRequest('GET', '/api/oauth/consent')),
            'accord' => fn(): mixed => $this->service()->decide($this->decision(true), $this->account),
            'refus' => fn(): mixed => $this->service()->decide($this->decision(false), $this->account),
        ];

        foreach ($calls as $label => $call) {
            try {
                $call();
                self::fail(\sprintf('%s : un refus du validateur doit être rejeté.', $label));
            } catch (AuthorizationRequestRejectedException $rejection) {
                self::assertSame($refusal, $rejection->getPrevious(), $label);
                self::assertSame(400, $rejection->getStatus(), $label);
            }
        }

        self::assertSame([], $this->effects);
    }

    /**
     * La décision est rendue au validateur sous les noms OAuth, comme une
     * demande du point d'autorisation : la même chaîne de requête que ses
     * paramètres, un champ absent omis.
     */
    public function testTheDecisionReachesTheValidatorAsAnAuthorizationRequest(): void
    {
        $this->service()->decide(new ConsentDecision(
            responseType: 'code',
            clientId: self::CLIENT_ID,
            redirectUri: self::REDIRECT,
            scope: null,
            state: 'a b&c',
            codeChallenge: 'challenge',
            codeChallengeMethod: 'S256',
            resource: self::API_BASE.'/api/mcp',
            approved: false,
        ), $this->account);

        $expected = [
            'response_type' => 'code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => self::REDIRECT,
            'state' => 'a b&c',
            'code_challenge' => 'challenge',
            'code_challenge_method' => 'S256',
            'resource' => self::API_BASE.'/api/mcp',
        ];
        self::assertNotNull($this->validated);
        self::assertSame($expected, $this->validated->getQueryParams());
        self::assertSame(http_build_query($expected), $this->validated->getUri()->getQuery());
        self::assertSame('/api/oauth/authorize', $this->validated->getUri()->getPath());
    }

    /**
     * La borne elle-même : une authentification d'exactement dix minutes
     * (ConsentService::MAX_AUTHENTICATION_AGE) suffit encore à accorder.
     */
    public function testAnApprovalAtTheAgeLimitIsGranted(): void
    {
        $this->authenticatedAt = new DateTimeImmutable(self::GRANTED_AT)->sub(new DateInterval(ConsentService::MAX_AUTHENTICATION_AGE));

        $this->service()->decide($this->decision(true), $this->account);

        self::assertSame(['begin', 'add', 'approve', 'commit', 'audit'], $this->effects);
    }

    /**
     * Une seconde au-delà : une authentification trop ancienne n'accorde
     * rien — un cookie volé (JWT d'une heure, non révocable, ADR 0003) ne
     * suffit pas à ouvrir une connexion de trente jours. Le 401 typé, ni
     * transaction, ni connexion, ni code, ni audit.
     *
     * @return iterable<string, array{DateTimeImmutable|null}>
     */
    public static function staleAuthentications(): iterable
    {
        yield 'une seconde au-delà de la borne' => [new DateTimeImmutable(self::GRANTED_AT)->sub(new DateInterval(ConsentService::MAX_AUTHENTICATION_AGE))->modify('-1 second')];
        yield 'une heure' => [new DateTimeImmutable(self::GRANTED_AT)->sub(new DateInterval('PT1H'))];
        yield 'instant inconnu' => [null];
    }

    #[DataProvider('staleAuthentications')]
    public function testAnApprovalOnAStaleAuthenticationRequiresReauthentication(?DateTimeImmutable $authenticatedAt): void
    {
        $this->authenticatedAt = $authenticatedAt;

        try {
            $this->service()->decide($this->decision(true), $this->account);
            self::fail('Une authentification trop ancienne ne doit rien accorder.');
        } catch (ReauthenticationRequiredException $refusal) {
            self::assertSame(401, $refusal->getStatus());
            self::assertSame('/errors/reauthentication-required', $refusal->getType());
        }

        self::assertSame([], $this->effects);
        self::assertNull($this->added);
        self::assertNull($this->audited);
    }

    /**
     * Le refus n'exige pas d'authentification récente : il n'accorde rien, et
     * une personne qui refuse n'a pas à se reconnecter pour le dire.
     */
    public function testADenialNeedsNoRecentAuthentication(): void
    {
        $this->authenticatedAt = null;

        $location = $this->service()->decide($this->decision(false), $this->account);

        self::assertStringContainsString('error=access_denied', $location);
        self::assertSame(['deny'], $this->effects);
    }

    /**
     * La demande est jugée d'abord : une demande devenue invalide reste le
     * 400 typé, même sur une authentification trop ancienne — se reconnecter
     * n'y changerait rien.
     */
    public function testAnInvalidRequestIsRejectedBeforeTheAuthenticationAgeIsJudged(): void
    {
        $this->authenticatedAt = null;
        $this->validatorRefusal = OAuthServerException::invalidRequest('redirect_uri');

        $this->expectException(AuthorizationRequestRejectedException::class);

        $this->service()->decide($this->decision(true), $this->account);
    }

    private function service(): ConsentService
    {
        $validator = self::createStub(AuthorizationRequestValidatorInterface::class);
        $validator->method('validate')->willReturnCallback(function (ServerRequestInterface $request): AuthorizationRequestSummary {
            $this->validated = $request;

            if (null !== $this->validatorRefusal) {
                throw $this->validatorRefusal;
            }

            return $this->summary();
        });

        $completer = self::createStub(AuthorizationCompleterInterface::class);
        $completer->method('approve')->willReturnCallback(function (): string {
            $this->effects[] = 'approve';

            if (null !== $this->approvalFailure) {
                throw $this->approvalFailure;
            }

            return self::REDIRECT.'?code=c&state=s';
        });
        $completer->method('deny')->willReturnCallback(function (): string {
            $this->effects[] = 'deny';

            return self::REDIRECT.'?state=s&error=access_denied';
        });

        $transaction = self::createStub(TransactionInterface::class);
        $transaction->method('run')->willReturnCallback(function (Closure $operation): mixed {
            $this->effects[] = 'begin';
            $result = $operation();

            if ($this->commitRefused) {
                throw new RuntimeException('COMMIT refusé');
            }

            $this->effects[] = 'commit';

            return $result;
        });

        $connections = self::createStub(McpConnectionRepositoryInterface::class);
        $connections->method('add')->willReturnCallback(function (McpConnection $connection): void {
            $this->effects[] = 'add';
            $this->added = $connection;
        });
        $connections->method('revokeOtherActiveOf')->willReturnCallback(function (McpConnection $kept, RevocationCause $cause, DateTimeImmutable $at): array {
            $this->effects[] = 'supersede';
            $this->supersededWith = [$kept, $cause, $at];

            return $this->superseded;
        });

        $auditLogger = self::createStub(SecurityAuditLoggerInterface::class);
        $auditLogger->method('mcpConnectionGranted')->willReturnCallback(function (McpConnectionReference $connection): void {
            $this->effects[] = 'audit';
            $this->audited = [$connection->accountId(), $connection->connectionId(), $connection->clientId()];
        });
        $auditLogger->method('mcpConnectionRevoked')->willReturnCallback(function (McpConnectionReference $connection, string $cause): void {
            $this->effects[] = 'revoked';
            $this->revokedAudits[] = [$connection, $cause];
        });
        // Le remplacement a sa propre méthode depuis #726 (B3) : une ligne
        // `superseded` écrite à l'approbation passerait par elle.
        $auditLogger->method('mcpConnectionSuperseded')->willReturnCallback(function (McpConnectionReference $connection, Uuid $supersededBy): void {
            $this->effects[] = 'revoked';
            $this->revokedAudits[] = [$connection, RevocationCause::Superseded->value.' by '.$supersededBy->toRfc4122()];
        });

        return new ConsentService(
            $validator,
            $completer,
            $transaction,
            new McpClientRepositoryFake($this->clientRegistered ? $this->client : null, null),
            $connections,
            $auditLogger,
            new OAuthEndpoints(self::API_BASE, 'https://site.example.test'),
            new MockClock(self::GRANTED_AT),
            new Psr17Factory(),
            $this->authenticationInstant(),
        );
    }

    /** Une connexion que le dépôt dit avoir remplacée : le même compte, le même client. */
    private function revokedConnection(): RevokedConnection
    {
        return new RevokedConnection(new ConnectionReference(Uuid::v7(), $this->account->getId()->toRfc4122(), self::CLIENT_ID), RevocationCause::Superseded);
    }

    private function authenticationInstant(): AuthenticationInstantInterface
    {
        $instant = self::createStub(AuthenticationInstantInterface::class);
        $instant->method('authenticatedAt')->willReturn($this->authenticatedAt);

        return $instant;
    }

    private function summary(): AuthorizationRequestSummary
    {
        return new AuthorizationRequestSummary(
            clientId: self::CLIENT_ID,
            clientName: 'Client de test',
            redirectUri: self::REDIRECT,
            redirectHost: 'localhost',
            scope: 'nominative:read',
            resource: self::API_BASE.'/api/mcp',
            authorizationRequest: new AuthorizationRequest(),
        );
    }

    private function decision(bool $approved): ConsentDecision
    {
        return new ConsentDecision(
            responseType: 'code',
            clientId: self::CLIENT_ID,
            redirectUri: self::REDIRECT,
            scope: 'nominative:read',
            state: 's',
            codeChallenge: 'challenge',
            codeChallengeMethod: 'S256',
            resource: self::API_BASE.'/api/mcp',
            approved: $approved,
        );
    }
}
