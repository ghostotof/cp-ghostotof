<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Application;

use App\Mcp\Connection\Application\FailSafeAudit;
use App\Mcp\Connection\Application\McpClientRegistrar;
use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Exception\McpClientAlreadyRegisteredException;
use App\Mcp\Connection\Domain\Exception\McpClientIdOfVerifiedDomainException;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\AllowedClientDomains;
use App\Mcp\Connection\Domain\ValueObject\ClientRegistration;
use App\Mcp\Connection\Domain\ValueObject\McpClientId;
use App\Mcp\Connection\Domain\ValueObject\McpClientName;
use App\Mcp\Connection\Domain\ValueObject\RedirectUri;
use App\Security\Authentication\Application\AuditOrigin;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Tests\Mcp\Connection\RegisteredMcpClient;
use DateTimeImmutable;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Throwable;

/**
 * L'enregistrement d'un client MCP (#647), sur un dépôt factice : la lecture
 * préalable, puis la contrainte unique comme dernier rempart — reconnue par le
 * nom de son index, jamais supposée.
 */
final class McpClientRegistrarTest extends TestCase
{
    private const string CLIENT_ID = 'claude-code-dev';

    public function testRegistersTheClientWithItsRedirectsAtTheClockInstant(): void
    {
        $repository = $this->repository();
        $clock = new MockClock(new DateTimeImmutable('2026-10-25T02:30:00+02:00'));

        $client = new McpClientRegistrar($repository, $clock, self::createStub(SecurityAuditLoggerInterface::class), new FailSafeAudit(new Logger('app', [new TestHandler()])), $this->allowedDomains())->register(McpClientId::fromString(self::CLIENT_ID), McpClientName::fromString('Claude Code'), [
            RedirectUri::fromString('http://localhost/callback'),
            RedirectUri::fromString('https://app.example/cb'),
        ], AuditOrigin::Console);

        self::assertSame([$client], $repository->added);
        self::assertSame(self::CLIENT_ID, $client->getClientId());
        self::assertSame('Claude Code', $client->getName());
        self::assertSame(['http://localhost/callback', 'https://app.example/cb'], $client->redirectUris());
        self::assertSame($clock->now()->getTimestamp(), $client->registeredAt()->getTimestamp());
    }

    /**
     * L'audit suit l'écriture : le client est déjà ajouté quand la ligne part,
     * et l'origine reçue est celle que l'appelant a déclarée.
     */
    public function testARegistrationIsAuditedWithItsOrigin(): void
    {
        $repository = $this->repository();
        $logger = $this->createMock(SecurityAuditLoggerInterface::class);
        $logger->expects(self::once())
            ->method('mcpClientRegistered')
            ->with(self::CLIENT_ID, ClientRegistration::Preregistered->value, AuditOrigin::Console)
            ->willReturnCallback(static function () use ($repository): void {
                self::assertCount(1, $repository->added, 'L\'audit part après l\'écriture, jamais avant.');
            });

        $this->register($repository, $logger);
    }

    /**
     * Une panne du journal ne défait pas une inscription déjà écrite : le client
     * est ajouté, le client est rendu, rien ne remonte.
     */
    public function testAFailingAuditDoesNotUndoTheRegistration(): void
    {
        $repository = $this->repository();
        $logger = self::createStub(SecurityAuditLoggerInterface::class);
        $logger->method('mcpClientRegistered')->willThrowException(new RuntimeException('client cité claude-code-dev'));

        $mainChannel = new TestHandler();

        $client = $this->register($repository, $logger, $mainChannel);

        self::assertSame([$client], $repository->added);
        // La panne est journalisée sous le message constant, la classe seule :
        // ni le message de l'exception ni l'identifiant du client n'en sortent.
        $records = $mainChannel->getRecords();
        self::assertCount(1, $records);
        self::assertSame(Level::Critical, $records[0]->level);
        self::assertSame('MCP client registration not audited.', $records[0]->message);
        self::assertSame(['exceptionClass' => RuntimeException::class], $records[0]->context);
    }

    /** Un refus n'a rien écrit : il n'audite rien, ni avant ni après la lecture. */
    public function testARefusedRegistrationAuditsNothing(): void
    {
        $repository = $this->repository(existing: RegisteredMcpClient::of(self::CLIENT_ID, 'Premier', ['http://localhost/callback']));
        $logger = $this->createMock(SecurityAuditLoggerInterface::class);
        $logger->expects(self::never())->method('mcpClientRegistered');

        $this->expectException(McpClientAlreadyRegisteredException::class);

        $this->register($repository, $logger);
    }

    /** Deux enregistrements simultanés : la base refuse, rien n'est écrit, rien n'est audité. */
    public function testARegistrationTakenMeanwhileAuditsNothing(): void
    {
        $repository = $this->repository(failure: $this->violation(self::english(McpClient::CLIENT_ID_UNIQUE_INDEX, 'client_id', self::CLIENT_ID)));
        $logger = $this->createMock(SecurityAuditLoggerInterface::class);
        $logger->expects(self::never())->method('mcpClientRegistered');

        $this->expectException(McpClientAlreadyRegisteredException::class);

        $this->register($repository, $logger);
    }

    /**
     * Une ligne pré-enregistrée l'emporte sur CIMD (règle D-C de l'admission) :
     * enregistrer l'URL de métadonnées d'un domaine vérifié masquerait son vrai
     * client. Le refus est celui du domaine, donc aussi celui du CLI, qui
     * n'a pas le validateur du backoffice. Rien n'est écrit, rien n'est audité.
     */
    #[DataProvider('metadataUrlsOfAVerifiedDomain')]
    public function testTheMetadataUrlOfAVerifiedDomainIsRefusedWithoutWritingNorAuditing(string $clientId): void
    {
        $repository = $this->repository();
        $logger = $this->createMock(SecurityAuditLoggerInterface::class);
        $logger->expects(self::never())->method('mcpClientRegistered');

        try {
            new McpClientRegistrar($repository, new MockClock(), $logger, new FailSafeAudit(new Logger('app', [new TestHandler()])), $this->allowedDomains())
                ->register(McpClientId::fromString($clientId), McpClientName::fromString('Claude Code'), [RedirectUri::fromString('http://localhost/callback')], AuditOrigin::Console);
            self::fail('L\'enregistrement aurait dû être refusé.');
        } catch (McpClientIdOfVerifiedDomainException $exception) {
            self::assertSame(McpClientIdOfVerifiedDomainException::cannotBePreregistered()->getMessage(), $exception->getMessage());
            self::assertStringNotContainsString($clientId, $exception->getMessage());
        }

        self::assertSame([], $repository->added);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function metadataUrlsOfAVerifiedDomain(): iterable
    {
        yield 'premier domaine vérifié' => ['https://claude.ai/oauth/claude-code-client-metadata'];
        yield 'second domaine vérifié' => ['https://chatgpt.com/oauth/client.json'];
    }

    /**
     * Le contrôle passe avant la pré-lecture : même pris, l'identifiant d'un
     * domaine vérifié est refusé pour ce qu'il est, pas pour un conflit.
     */
    public function testTheVerifiedDomainRefusalComesBeforeTheAlreadyTakenRead(): void
    {
        $clientId = 'https://claude.ai/oauth/claude-code-client-metadata';
        $repository = $this->repository(existing: RegisteredMcpClient::of($clientId, 'Premier', ['http://localhost/callback']));

        $this->expectException(McpClientIdOfVerifiedDomainException::class);

        new McpClientRegistrar($repository, new MockClock(), self::createStub(SecurityAuditLoggerInterface::class), new FailSafeAudit(new Logger('app', [new TestHandler()])), $this->allowedDomains())
            ->register(McpClientId::fromString($clientId), McpClientName::fromString('Claude Code'), [RedirectUri::fromString('http://localhost/callback')], AuditOrigin::Console);
    }

    /**
     * Ce que l'admission ne confondrait pas avec un client d'un domaine
     * vérifié reste un identifiant ordinaire.
     */
    #[DataProvider('clientIdsThatAreNotAVerifiedDomainClient')]
    public function testAnIdentifierThatIsNotOfAVerifiedDomainIsStillRegistered(string $clientId): void
    {
        $repository = $this->repository();

        $client = new McpClientRegistrar($repository, new MockClock(), self::createStub(SecurityAuditLoggerInterface::class), new FailSafeAudit(new Logger('app', [new TestHandler()])), $this->allowedDomains())
            ->register(McpClientId::fromString($clientId), McpClientName::fromString('Claude Code'), [RedirectUri::fromString('http://localhost/callback')], AuditOrigin::Console);

        self::assertSame([$client], $repository->added);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function clientIdsThatAreNotAVerifiedDomainClient(): iterable
    {
        yield 'domaine non listé' => ['https://client.example.test/metadata.json'];
        yield 'sous-domaine d\'un domaine listé' => ['https://evil.claude.ai/metadata.json'];
        yield 'domaine listé avec un port' => ['https://claude.ai:443/metadata.json'];
        yield 'domaine listé en http' => ['http://claude.ai/metadata.json'];
        yield 'domaine listé en majuscules' => ['https://Claude.ai/metadata.json'];
        yield 'identifiant ordinaire' => [self::CLIENT_ID];
    }

    public function testAClientIdAlreadyRegisteredIsRefusedBeforeAnyWrite(): void
    {
        $repository = $this->repository(existing: RegisteredMcpClient::of(self::CLIENT_ID, 'Premier', ['http://localhost/callback']));

        try {
            $this->register($repository);
            self::fail('L\'enregistrement aurait dû être refusé.');
        } catch (McpClientAlreadyRegisteredException $exception) {
            self::assertSame(McpClientAlreadyRegisteredException::alreadyTaken()->getMessage(), $exception->getMessage());
        }

        self::assertSame([], $repository->added);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function clientIdViolations(): iterable
    {
        yield 'message anglais' => [self::english(McpClient::CLIENT_ID_UNIQUE_INDEX, 'client_id', self::CLIENT_ID)];
        // lc_messages en français : chevrons, et espaces insécables autour.
        yield 'message français' => ["ERREUR:  la valeur d'une clé dupliquée rompt la contrainte unique «\u{a0}".McpClient::CLIENT_ID_UNIQUE_INDEX."\u{a0}»\nDÉTAIL : La clé « (client_id)=(claude-code-dev) » existe déjà."];
    }

    /**
     * Deux enregistrements simultanés : la lecture n'a rien vu, la base refuse
     * sous l'index de `client_id`. Le refus nommé ne cite pas l'identifiant et
     * ne garde pas la violation.
     */
    #[DataProvider('clientIdViolations')]
    public function testAViolationOfTheClientIdIndexIsTakenMeanwhile(string $driverMessage): void
    {
        $repository = $this->repository(failure: $this->violation($driverMessage));

        try {
            $this->register($repository);
            self::fail('L\'enregistrement aurait dû être refusé.');
        } catch (McpClientAlreadyRegisteredException $exception) {
            self::assertSame(McpClientAlreadyRegisteredException::takenMeanwhile()->getMessage(), $exception->getMessage());
            self::assertStringNotContainsString(self::CLIENT_ID, $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherViolations(): iterable
    {
        yield 'autre index' => [self::english('uniq_mcp_client_other', 'other', 'x')];
        // Le DETAIL cite la valeur : un identifiant qui porte le nom de l'index
        // ne doit pas faire prendre une autre violation pour la sienne.
        yield 'autre index, valeur qui cite le nôtre' => [self::english('uniq_mcp_client_other', 'other', '"'.McpClient::CLIENT_ID_UNIQUE_INDEX.'"')];
        yield 'aucun index cité' => ['ERROR:  duplicate key value'];
    }

    /** Toute autre unicité remonte telle quelle : aucune cause affirmée à tort. */
    #[DataProvider('otherViolations')]
    public function testAnyOtherUniqueViolationIsRethrownAsIs(string $driverMessage): void
    {
        $violation = $this->violation($driverMessage);
        $repository = $this->repository(failure: $violation);

        try {
            $this->register($repository);
            self::fail('La violation aurait dû remonter.');
        } catch (Throwable $thrown) {
            self::assertSame($violation, $thrown);
        }
    }

    private function register(McpClientRepositoryInterface $repository, ?SecurityAuditLoggerInterface $logger = null, ?TestHandler $mainChannel = null): McpClient
    {
        return new McpClientRegistrar($repository, new MockClock(), $logger ?? self::createStub(SecurityAuditLoggerInterface::class), new FailSafeAudit(new Logger('app', [$mainChannel ?? new TestHandler()])), $this->allowedDomains())->register(McpClientId::fromString(self::CLIENT_ID), McpClientName::fromString('Claude Code'), [RedirectUri::fromString('http://localhost/callback')], AuditOrigin::Console);
    }

    /** Les domaines de `app.mcp.cimd.allowed_domains`, tels que la configuration les déclare. */
    private function allowedDomains(): AllowedClientDomains
    {
        return AllowedClientDomains::fromList(['claude.ai', 'chatgpt.com']);
    }

    private static function english(string $index, string $column, string $value): string
    {
        return \sprintf("SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"%s\"\nDETAIL:  Key (%s)=(%s) already exists.", $index, $column, $value);
    }

    private function violation(string $driverMessage): UniqueConstraintViolationException
    {
        $driverException = new class($driverMessage, '23505') extends AbstractException {
        };

        return new UniqueConstraintViolationException($driverException, null);
    }

    /**
     * Un dépôt en mémoire : `existing` répond à la lecture, `failure` est levée
     * par l'ajout, `added` garde ce qui a été ajouté.
     */
    private function repository(?McpClient $existing = null, ?Throwable $failure = null): McpClientRepositoryFake
    {
        return new McpClientRepositoryFake($existing, $failure);
    }
}
