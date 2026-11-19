<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Application;

use App\Mcp\Connection\Application\FailSafeAudit;
use App\Mcp\Connection\Application\McpClientAdministrator;
use App\Mcp\Connection\Application\McpClientRegistrarInterface;
use App\Mcp\Connection\Application\RevokedConnectionAudit;
use App\Mcp\Connection\Application\TransactionInterface;
use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\Exception\McpClientDeclaredByDocumentException;
use App\Mcp\Connection\Domain\Exception\McpClientNotFoundException;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\McpClientId;
use App\Mcp\Connection\Domain\ValueObject\McpClientName;
use App\Mcp\Connection\Domain\ValueObject\RedirectUri;
use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Shared\Application\DeadlockRetryInterface;
use App\Tests\Mcp\Connection\Fixtures\ClaudeCodeMetadata;
use App\Tests\Mcp\Connection\RegisteredMcpClient;
use App\Tests\Support\ReadsSecurityAuditLog;
use App\Tests\Support\TestDatabase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * L'administration d'un client MCP depuis le backoffice (#652), sur la base de
 * test : l'enregistrement délègue avec l'origine « requête » ; la révision
 * n'audite que ce qu'elle a validé ; la suppression coupe et audite chaque
 * connexion active, **après** le COMMIT seulement.
 */
final class McpClientAdministratorTest extends KernelTestCase
{
    use ReadsSecurityAuditLog;

    /** Les tables du modèle, des feuilles vers la racine. */
    private const array TABLES = ['mcp_refresh_token', 'mcp_access_token', 'mcp_authorization_code', 'mcp_connection', 'mcp_client'];

    private const string CLIENT_ID = 'claude-code-dev';
    private const string SCOPE = 'nominative:read';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->purgeTables();
    }

    protected function tearDown(): void
    {
        $this->purgeTables();
        parent::tearDown();
    }

    /**
     * Deux connexions actives et une déjà révoquée : la suppression en coupe
     * deux (la révoquée n'a plus rien à couper), les audite une à une avec la
     * cause du journal, puis audite le client avec leur nombre. Le client et
     * toutes ses connexions ont disparu de la base.
     */
    public function testRemovingCutsEveryActiveConnectionAndAuditsEach(): void
    {
        $client = $this->persistedClient();
        $first = $this->grant($client, 'jane');
        $second = $this->grant($client, 'john');
        $revoked = $this->grant($client, 'jade');
        $this->revokeOwn($revoked);
        $this->em->clear();

        $cut = $this->administrator()->remove($client->getId());

        self::assertSame(2, $cut);
        $revocations = self::securityAuditEvents('mcp-connection-revoked');
        self::assertCount(2, $revocations);
        $audited = array_map(static fn (array $event): mixed => $event['connectionId'] ?? null, $revocations);
        sort($audited);
        $expected = [$first->getId()->toRfc4122(), $second->getId()->toRfc4122()];
        sort($expected);
        self::assertSame($expected, $audited);
        foreach ($revocations as $event) {
            self::assertSame('client-removed', $event['cause']);
            self::assertSame(self::CLIENT_ID, $event['clientId']);
        }
        $removed = self::singleSecurityAuditEvent('mcp-client-removed');
        self::assertSame(self::CLIENT_ID, $removed['clientId']);
        self::assertSame('preregistered', $removed['registration']);
        self::assertSame(2, $removed['cutConnections']);
        self::assertSame(0, $this->rowCount('mcp_client'));
        self::assertSame(0, $this->rowCount('mcp_connection'));
    }

    /** Les connexions sont coupées **avant** que le client ne soit audité : l'ordre du journal suit l'ordre des faits. */
    public function testTheClientIsAuditedAfterItsConnections(): void
    {
        $client = $this->persistedClient();
        $this->grant($client, 'jane');
        $this->em->clear();

        $this->administrator()->remove($client->getId());

        $events = [];
        foreach (self::securityAuditRecords() as $record) {
            $event = $record->context['event'] ?? null;
            if (\in_array($event, ['mcp-connection-revoked', 'mcp-client-removed'], true)) {
                $events[] = $event;
            }
        }

        self::assertSame(['mcp-connection-revoked', 'mcp-client-removed'], $events);
    }

    /** Un client sans connexion active : rien à couper, un seul événement, de compte zéro. */
    public function testRemovingAClientWithoutConnectionAuditsOnlyTheClient(): void
    {
        $client = $this->persistedClient();
        $this->em->clear();

        self::assertSame(0, $this->administrator()->remove($client->getId()));

        self::assertSame([], self::securityAuditEvents('mcp-connection-revoked'));
        self::assertSame(0, self::singleSecurityAuditEvent('mcp-client-removed')['cutConnections']);
        self::assertSame(0, $this->rowCount('mcp_client'));
    }

    public function testRemovingAnUnknownClientIsNotFoundAndAuditsNothing(): void
    {
        try {
            $this->administrator()->remove(Uuid::v7());
            self::fail('Un client inconnu doit lever McpClientNotFoundException.');
        } catch (McpClientNotFoundException $notFound) {
            self::assertSame(404, $notFound->getStatus());
        }

        self::assertSame([], self::securityAuditRecords());
    }

    /**
     * Supprimer un client déclaré par son document est permis (T8) : ce n'est
     * pas un bannissement, il reviendra à la prochaine découverte. L'événement
     * garde son mode d'enregistrement.
     */
    public function testRemovingACimdClientIsAllowed(): void
    {
        $client = ClaudeCodeMetadata::storedRow();
        self::getContainer()->get(McpClientRepositoryInterface::class)->saveDiscovered($client);
        $this->em->clear();

        $cut = $this->administrator()->remove($client->getId());

        self::assertSame(0, $cut);
        self::assertSame('cimd', self::singleSecurityAuditEvent('mcp-client-removed')['registration']);
        self::assertSame(0, $this->rowCount('mcp_client'));
    }

    /**
     * La suppression annulée au moment du COMMIT (la transaction avorte après
     * l'écriture) n'émet **aucun** événement et ne retire rien : un acte
     * journalisé est un acte validé.
     */
    public function testTheAuditFollowsTheCommit(): void
    {
        $client = $this->persistedClient();
        $this->grant($client, 'jane');
        $this->em->clear();

        try {
            $this->administratorRunningIn(new RollingBackTransaction(self::getContainer()->get(TransactionInterface::class)))->remove($client->getId());
            self::fail('La transaction annulée doit remonter son exception.');
        } catch (RolledBackOnPurposeException) {
            // Attendu : l'opération a tout écrit, puis la base a annulé.
        }

        self::assertSame([], self::securityAuditRecords());
        self::assertSame(1, $this->rowCount('mcp_client'));
        self::assertSame(1, $this->rowCount('mcp_connection'));
    }

    /**
     * Toute la transaction de la suppression est confiée à `retryOnce()`
     * (T1) : l'opération s'y déroule une fois, entière, et le résultat est
     * celui du seul essai qui a abouti.
     */
    public function testRemovingRunsItsWholeTransactionThroughTheDeadlockRetry(): void
    {
        $client = $this->persistedClient();
        $this->grant($client, 'jane');
        $this->em->clear();
        $retry = new CountingDeadlockRetry(self::getContainer()->get(DeadlockRetryInterface::class));

        $cut = $this->administrator($retry)->remove($client->getId());

        self::assertSame(1, $retry->operations());
        self::assertSame(1, $cut);
        self::assertSame(0, $this->rowCount('mcp_connection'));
    }

    public function testRevisingAuditsTheRedirectCount(): void
    {
        $client = $this->persistedClient();
        $this->em->clear();

        $revised = $this->administrator()->revise($client->getId(), McpClientName::fromString('Nouveau nom'), [
            RedirectUri::fromString('https://client.example.test/one'),
            RedirectUri::fromString('https://client.example.test/two'),
            RedirectUri::fromString('https://client.example.test/two'),
        ]);

        // Les doublons sont retirés par l'entité : le journal compte ce qui est écrit.
        self::assertSame('Nouveau nom', $revised->getName());
        $this->em->clear();
        $stored = self::getContainer()->get(McpClientRepositoryInterface::class)->findOneByClientId(self::CLIENT_ID);
        self::assertSame(['https://client.example.test/one', 'https://client.example.test/two'], $stored?->redirectUris());
        $event = self::singleSecurityAuditEvent('mcp-client-revised');
        self::assertSame(self::CLIENT_ID, $event['clientId']);
        self::assertSame(2, $event['redirectUriCount']);
    }

    /**
     * Écart ACCEPTÉ (décision du 2026-11-19) : l'audit de révision compte les
     * redirections après déduplication par l'entité, pas celles que l'appelant a
     * soumises. Deux redirections identiques soumises donnent 1 au journal. Ce test
     * fige ce comportement : le changer est une décision, pas une correction.
     */
    public function testRevisingAuditsTheDeduplicatedRedirectCountAnAcceptedGap(): void
    {
        $client = $this->persistedClient();
        $this->em->clear();

        $this->administrator()->revise($client->getId(), McpClientName::fromString('Nouveau nom'), [
            RedirectUri::fromString('https://client.example.test/one'),
            RedirectUri::fromString('https://client.example.test/one'),
        ]);

        $event = self::singleSecurityAuditEvent('mcp-client-revised');
        self::assertSame(1, $event['redirectUriCount']);
    }

    /**
     * Le journal dit ce qui a changé, jamais lesquelles : remplacer la
     * redirection existante par une autre laisse 1 ajoutée et 1 retirée, et non
     * le même journal qu'un changement anodin. Aucune URI n'en sort.
     *
     * @param list<string> $initial
     * @param non-empty-list<string> $submitted
     */
    #[DataProvider('redirectChanges')]
    public function testRevisingAuditsWhatWasAddedAndRemovedNeverTheUris(array $initial, array $submitted, int $total, int $added, int $removed): void
    {
        $client = $this->persistedClient($initial);
        $this->em->clear();

        $this->administrator()->revise(
            $client->getId(),
            McpClientName::fromString('Claude Code'),
            array_map(static fn (string $uri): RedirectUri => RedirectUri::fromString($uri), $submitted),
        );

        $event = self::singleSecurityAuditEvent('mcp-client-revised');
        self::assertSame($total, $event['redirectUriCount']);
        self::assertSame($added, $event['addedRedirectCount']);
        self::assertSame($removed, $event['removedRedirectCount']);
        self::assertStringNotContainsString('client.example.test', json_encode($event, \JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{list<string>, non-empty-list<string>, int, int, int}> */
    public static function redirectChanges(): iterable
    {
        $callback = 'https://client.example.test/callback';
        $two = 'https://client.example.test/two';

        yield 'remplacement 1 pour 1' => [[$callback], ['https://attacker.example.test/cb'], 1, 1, 1];
        yield 'ajout pur' => [[$callback], [$callback, $two], 2, 1, 0];
        yield 'retrait pur' => [[$callback, $two], [$callback], 1, 0, 1];
        yield 'révision identique' => [[$callback], [$callback], 1, 0, 0];
        yield 'doublons soumis, comptés une fois' => [[$callback], [$callback, $two, $two], 2, 1, 0];
    }

    /**
     * Une ligne écrite à la main, ou avant la règle de dédoublonnage, peut porter
     * deux fois la même redirection : le journal compte des ensembles, pas des lignes.
     */
    public function testARowWithDuplicatedRedirectsIsCountedAsASet(): void
    {
        $client = $this->persistedClient(['https://client.example.test/callback']);
        $this->em->getConnection()->executeStatement(
            'UPDATE mcp_client SET redirect_uris = :uris WHERE id = :id',
            ['uris' => json_encode(['https://client.example.test/a', 'https://client.example.test/a'], \JSON_THROW_ON_ERROR), 'id' => $client->getId()->toRfc4122()],
        );
        $this->em->clear();

        $this->administrator()->revise($client->getId(), McpClientName::fromString('Claude Code'), [
            RedirectUri::fromString('https://client.example.test/b'),
        ]);

        $event = self::singleSecurityAuditEvent('mcp-client-revised');
        self::assertSame(1, $event['addedRedirectCount']);
        self::assertSame(1, $event['removedRedirectCount']);
    }

    /** La révision ne coupe aucune connexion : elles survivent, intactes et actives. */
    public function testRevisingCutsNoConnection(): void
    {
        $client = $this->persistedClient();
        $this->grant($client, 'jane');
        $this->em->clear();

        $this->administrator()->revise($client->getId(), McpClientName::fromString('Nouveau nom'), [RedirectUri::fromString('https://client.example.test/one')]);

        self::assertSame(1, $this->rowCount('mcp_connection'));
        self::assertSame([], self::securityAuditEvents('mcp-connection-revoked'));
    }

    public function testRevisingAnUnknownClientIsNotFound(): void
    {
        $this->expectException(McpClientNotFoundException::class);

        try {
            $this->administrator()->revise(Uuid::v7(), McpClientName::fromString('Nom'), [RedirectUri::fromString('https://client.example.test/one')]);
        } finally {
            self::assertSame([], self::securityAuditRecords());
        }
    }

    /** Un client déclaré par son document ne se révise pas : rien n'est écrit, rien n'est audité. */
    public function testRevisingACimdClientIsRefusedAndAuditsNothing(): void
    {
        $client = ClaudeCodeMetadata::storedRow();
        $repository = self::getContainer()->get(McpClientRepositoryInterface::class);
        $repository->saveDiscovered($client);
        $this->em->clear();
        $nameBefore = $repository->findOneByClientId($client->getClientId())?->getName();
        $this->em->clear();

        try {
            $this->administrator()->revise($client->getId(), McpClientName::fromString('Autre nom'), [RedirectUri::fromString('https://client.example.test/one')]);
            self::fail('Un client CIMD ne se révise pas.');
        } catch (McpClientDeclaredByDocumentException) {
            // Attendu.
        }

        self::assertSame([], self::securityAuditRecords());
        $this->em->clear();
        self::assertSame($nameBefore, self::getContainer()->get(McpClientRepositoryInterface::class)->findOneByClientId($client->getClientId())?->getName());
    }

    /** Depuis le backoffice, l'enregistrement est celui d'une requête : l'acteur n'est jamais `console`. */
    public function testRegisteringAuditsARequestOrigin(): void
    {
        $client = $this->administrator()->register(
            McpClientId::fromString(self::CLIENT_ID),
            McpClientName::fromString('Claude Code'),
            [RedirectUri::fromString('https://client.example.test/callback')],
        );

        self::assertSame(self::CLIENT_ID, $client->getClientId());
        $event = self::singleSecurityAuditEvent('mcp-client-registered');
        self::assertSame(self::CLIENT_ID, $event['clientId']);
        self::assertNotSame('console', $event['actor']);
        self::assertSame(1, $this->rowCount('mcp_client'));
    }

    private function administrator(?DeadlockRetryInterface $retry = null): McpClientAdministrator
    {
        return $this->administratorRunningIn(self::getContainer()->get(TransactionInterface::class), $retry);
    }

    /** L'administrateur du conteneur, sa transaction (et, au besoin, sa relance) remplacées. */
    private function administratorRunningIn(TransactionInterface $transaction, ?DeadlockRetryInterface $retry = null): McpClientAdministrator
    {
        $container = self::getContainer();
        $failSafeAudit = new FailSafeAudit($container->get(LoggerInterface::class));
        $auditLogger = $container->get(SecurityAuditLoggerInterface::class);

        return new McpClientAdministrator(
            $container->get(McpClientRegistrarInterface::class),
            $container->get(McpClientRepositoryInterface::class),
            $container->get(McpConnectionRepositoryInterface::class),
            $transaction,
            $retry ?? $container->get(DeadlockRetryInterface::class),
            new RevokedConnectionAudit($auditLogger, $failSafeAudit),
            $failSafeAudit,
            $auditLogger,
        );
    }

    /** @param list<string> $redirects */
    private function persistedClient(array $redirects = ['https://client.example.test/callback']): McpClient
    {
        $client = RegisteredMcpClient::of(self::CLIENT_ID, 'Claude Code', $redirects);
        self::getContainer()->get(McpClientRepositoryInterface::class)->add($client);

        return $client;
    }

    /** Une connexion active du client, accordée à un compte propre au test. */
    private function grant(McpClient $client, string $username): McpConnection
    {
        $account = new CpgUser($username, 'hashed-password');
        $this->em->persist($account);
        $this->em->flush();
        $connection = McpConnection::grant($account, $client, self::SCOPE, new DateTimeImmutable());
        self::getContainer()->get(McpConnectionRepositoryInterface::class)->add($connection);

        return $connection;
    }

    /** La personne révoque sa connexion : elle n'est plus active, la suppression du client n'a rien à y couper. */
    private function revokeOwn(McpConnection $connection): void
    {
        self::getContainer()->get(TransactionInterface::class)->run(
            fn () => self::getContainer()->get(McpConnectionRepositoryInterface::class)
                ->revokeActive($connection->getId(), RevocationCause::Person, new DateTimeImmutable()),
        );
    }

    private function rowCount(string $table): int
    {
        $count = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
        self::assertIsInt($count);

        return $count;
    }

    private function purgeTables(): void
    {
        $connection = $this->em->getConnection();
        TestDatabase::assertSafeToWipe($connection);
        foreach (self::TABLES as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
        $connection->executeStatement('DELETE FROM cpg_user');
        $this->em->clear();
    }
}
