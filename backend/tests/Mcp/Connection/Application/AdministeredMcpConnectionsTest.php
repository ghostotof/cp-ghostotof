<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Application;

use App\Mcp\Connection\Application\AdministeredMcpConnections;
use App\Mcp\Connection\Application\AdministeredMcpConnectionView;
use App\Mcp\Connection\Application\McpConnectionStatus;
use App\Mcp\Connection\Application\OwnMcpConnectionState;
use App\Mcp\Connection\Application\ReadSnapshotInterface;
use App\Mcp\Connection\Domain\Entity\McpAuthorizationCode;
use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\Repository\McpAuthorizationCodeRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\ClientRegistration;
use App\Mcp\Connection\Domain\ValueObject\ConnectionLifetimes;
use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Mcp\Connection\RegisteredMcpClient;
use App\Tests\Support\TestDatabase;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * La lecture de l'administration (#652, T13) de {@see AdministeredMcpConnections}
 * sur la base `_test` : les connexions de tous les comptes, jugées à un instant
 * figé, vivantes toutes, terminées bornées — révoquées **et** accords abandonnés,
 * ces derniers rangés à leur fin réelle (octroi + délai du code non échangé).
 */
final class AdministeredMcpConnectionsTest extends KernelTestCase
{
    private const string NOW = '2026-11-20T12:00:00';
    private const string SCOPE = 'nominative:read';

    /** Les tables du modèle, des feuilles vers la racine. */
    private const array TABLES = ['mcp_refresh_token', 'mcp_access_token', 'mcp_authorization_code', 'mcp_connection', 'mcp_client'];

    private EntityManagerInterface $em;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->now = new DateTimeImmutable(self::NOW, new DateTimeZone('Europe/Paris'));
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    /**
     * Deux comptes, quatre connexions : une active, une échue que la purge n'a
     * pas marquée, une révoquée, un accord abandonné. Tous sont là, avec leur
     * état, du plus récemment octroyé au plus ancien.
     */
    public function testListsActiveExpiredAndRevokedOfEveryAccount(): void
    {
        $jane = $this->persistAccount('jane');
        $john = $this->persistAccount('john');
        $client = $this->registerClient('https://client.example.test/metadata.json');

        $active = $this->grant($jane, $client, $this->now->sub(new DateInterval('P1D')));
        $this->exchangeCodeOf($active);
        $revoked = $this->grant($jane, $client, $this->now->sub(new DateInterval('P5D')));
        $this->connections()->revokeActive($revoked->getId(), RevocationCause::Person, $this->now->sub(new DateInterval('P2D')));
        // Octroyée avant le plafond de vie : échue, mais la purge ne l'a pas marquée.
        $expired = $this->grant($john, $client, $this->now->sub(new DateInterval('P100D')));
        $this->exchangeCodeOf($expired);
        // Code jamais échangé, octroyée il y a trois heures : abandonnée.
        $abandoned = $this->grant($john, $client, $this->now->sub(new DateInterval('PT3H')));
        $this->em->clear();

        $views = $this->service()->list();

        self::assertSame(
            [$abandoned, $active, $revoked, $expired],
            array_map(fn (AdministeredMcpConnectionView $view): McpConnection => $this->byId([$abandoned, $active, $revoked, $expired], $view), $views),
            'Du plus récemment octroyé au plus ancien, tous comptes mêlés.',
        );
        self::assertSame(
            [OwnMcpConnectionState::Expired, OwnMcpConnectionState::Active, OwnMcpConnectionState::Revoked, OwnMcpConnectionState::Expired],
            array_map(static fn (AdministeredMcpConnectionView $view): OwnMcpConnectionState => $view->state, $views),
        );
        // L'accord abandonné : ni révoqué ni cause, code non échangé, échu.
        self::assertNull($views[0]->revokedAt);
        self::assertNull($views[0]->revocationCause);
        self::assertFalse($views[0]->codeExchanged);
        // La révoquée dit quand et pourquoi ; l'échue non marquée n'a ni l'un ni l'autre.
        self::assertSame($this->now->sub(new DateInterval('P2D'))->getTimestamp(), $views[2]->revokedAt?->getTimestamp());
        self::assertSame('person', $views[2]->revocationCause);
        self::assertNull($views[3]->revokedAt);
        self::assertTrue($views[3]->codeExchanged);
        // L'échéance est toujours rendue, y compris pour la révoquée.
        self::assertGreaterThan(0, $views[2]->expiresAt->getTimestamp());
    }

    /** La vue nomme le compte et le mode d'enregistrement du client. */
    public function testTheViewNamesTheAccountAndTheClientMode(): void
    {
        $account = $this->persistAccount('jane');
        $client = $this->registerClient('https://client.example.test/metadata.json');
        $connection = $this->grant($account, $client, $this->now->sub(new DateInterval('PT10M')));
        $this->exchangeCodeOf($connection);
        $this->em->clear();

        $views = $this->service()->list();

        self::assertCount(1, $views);
        self::assertSame('jane', $views[0]->accountUsername);
        self::assertSame('https://client.example.test/metadata.json', $views[0]->clientId);
        self::assertSame('Client de test', $views[0]->clientName);
        self::assertSame(ClientRegistration::Preregistered, $views[0]->clientRegistration);
        self::assertTrue($connection->getId()->equals($views[0]->id));
    }

    /** 201 révoquées : seules les 200 terminées le plus récemment restent. */
    public function testTerminatedAreBoundedToTheLimit(): void
    {
        $account = $this->persistAccount('jane');
        $client = $this->registerClient('https://client.example.test/metadata.json');
        $base = $this->now->sub(new DateInterval('P10D'));
        $this->insertRevoked($account, $client, $base, AdministeredMcpConnections::TERMINATED_LIMIT + 1);
        $this->em->clear();

        $views = $this->service()->list();

        self::assertCount(AdministeredMcpConnections::TERMINATED_LIMIT, $views);
        $revokedAt = array_map(static fn (AdministeredMcpConnectionView $view): int => $view->revokedAt?->getTimestamp() ?? 0, $views);
        // La plus ancienne (base + 1 minute) est sortie, la deuxième est gardée.
        self::assertSame($base->getTimestamp() + 2 * 60, min($revokedAt));
        self::assertSame($base->getTimestamp() + (AdministeredMcpConnections::TERMINATED_LIMIT + 1) * 60, max($revokedAt));
    }

    /**
     * Sur la limite, un accord abandonné se classe à sa fin réelle (octroi +
     * délai du code non échangé), pas à son octroi : plus récent que la plus
     * ancienne révoquée, il reste et c'est elle qui sort ; plus ancien, il sort.
     */
    public function testAnAbandonedGrantCompetesOnItsTrueEnd(): void
    {
        $account = $this->persistAccount('jane');
        $client = $this->registerClient('https://client.example.test/metadata.json');
        $base = $this->now->sub(new DateInterval('P10D'));
        $this->insertRevoked($account, $client, $base, AdministeredMcpConnections::TERMINATED_LIMIT);
        $unexchanged = $this->lifetimes()->unexchanged();
        // Fin à base + 30 minutes : plus récente que la révoquée de base + 1 minute.
        $keptEnd = $base->add(new DateInterval('PT30M'));
        $kept = $this->grant($account, $client, $keptEnd->sub($unexchanged));
        // Fin un jour avant la base : plus ancienne que toutes les révoquées.
        $droppedEnd = $base->sub(new DateInterval('P1D'));
        $dropped = $this->grant($account, $client, $droppedEnd->sub($unexchanged));
        $this->em->clear();

        $views = $this->service()->list();

        self::assertCount(AdministeredMcpConnections::TERMINATED_LIMIT, $views);
        $ids = array_map(static fn (AdministeredMcpConnectionView $view): string => $view->id->toRfc4122(), $views);
        self::assertContains($kept->getId()->toRfc4122(), $ids, 'Sa fin est plus récente que celle de la plus ancienne révoquée.');
        self::assertNotContains($dropped->getId()->toRfc4122(), $ids, 'Sa fin est plus ancienne que toutes celles gardées.');
        $revokedAt = array_map(static fn (AdministeredMcpConnectionView $view): int => $view->revokedAt?->getTimestamp() ?? \PHP_INT_MAX, $views);
        self::assertSame($base->getTimestamp() + 2 * 60, min($revokedAt), 'La révoquée de base + 1 minute a cédé sa place.');
    }

    /** Trois lectures, une par moitié, jointures comprises : aucune requête paresseuse pour le compte ni le client. */
    public function testListReadsInThreeQueries(): void
    {
        $jane = $this->persistAccount('jane');
        $john = $this->persistAccount('john');
        $client = $this->registerClient('https://client.example.test/metadata.json');
        $this->exchangeCodeOf($this->grant($jane, $client, $this->now->sub(new DateInterval('P1D'))));
        $revoked = $this->grant($john, $client, $this->now->sub(new DateInterval('P5D')));
        $this->connections()->revokeActive($revoked->getId(), RevocationCause::Client, $this->now->sub(new DateInterval('P2D')));
        $this->grant($john, $client, $this->now->sub(new DateInterval('PT3H')));
        $this->em->clear();
        $service = $this->service();

        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();
        $views = $service->list();
        $selects = [];
        foreach ($holder->getData()['default'] ?? [] as $query) {
            if (\is_string($query['sql'] ?? null) && str_starts_with(ltrim($query['sql']), 'SELECT')) {
                $selects[] = $query['sql'];
            }
        }

        self::assertCount(3, $views);
        self::assertCount(3, $selects, 'Une lecture par moitié (vivantes, révoquées, abandonnées), rien de plus.');
    }

    private function service(): AdministeredMcpConnections
    {
        $clock = new MockClock($this->now);

        return new AdministeredMcpConnections(
            $this->connections(),
            $clock,
            new McpConnectionStatus($this->lifetimes(), $clock),
            $this->lifetimes(),
            self::getContainer()->get(ReadSnapshotInterface::class),
        );
    }

    /**
     * @param list<McpConnection> $candidates
     */
    private function byId(array $candidates, AdministeredMcpConnectionView $view): McpConnection
    {
        foreach ($candidates as $candidate) {
            if ($candidate->getId()->equals($view->id)) {
                return $candidate;
            }
        }

        self::fail('Une vue ne correspond à aucune connexion octroyée.');
    }

    /**
     * Insère `$count` connexions révoquées en une instruction : la i-ème l'est à
     * `$base` + i minutes, octroyée un jour plus tôt, `generate_series` plutôt
     * qu'autant d'entités pour que le test reste rapide.
     */
    private function insertRevoked(CpgUser $account, McpClient $client, DateTimeImmutable $base, int $count): void
    {
        $this->em->getConnection()->executeStatement(
            'INSERT INTO mcp_connection (id, scope, granted_at, revoked_at, revocation_cause, account_id, mcp_client_id) '
            ."SELECT gen_random_uuid(), :scope, CAST(:base AS timestamptz) + make_interval(mins => i) - interval '1 day', "
            ."CAST(:base AS timestamptz) + make_interval(mins => i), 'person', :account, :client "
            .'FROM generate_series(1, :count) AS i',
            [
                'scope' => self::SCOPE,
                'base' => $base->format('Y-m-d\TH:i:sP'),
                'account' => $account->getId()->toRfc4122(),
                'client' => $client->getId()->toRfc4122(),
                'count' => $count,
            ],
        );
    }

    private function grant(CpgUser $account, McpClient $client, DateTimeImmutable $at): McpConnection
    {
        $connection = McpConnection::grant($account, $client, self::SCOPE, $at);
        $this->connections()->add($connection);

        return $connection;
    }

    /** Note l'échange du code de `$connection`, une minute après son octroi, par la voie de production. */
    private function exchangeCodeOf(McpConnection $connection): void
    {
        $at = $connection->grantedAt()->add(new DateInterval('PT1M'));
        $codeHash = hash('sha256', 'code-'.$connection->getId()->toRfc4122());
        $this->authorizationCodes()->add(new McpAuthorizationCode($connection, $codeHash, $at->add(new DateInterval('PT10M'))));
        $this->connections()->recordCodeExchangeOf($codeHash, $at);
    }

    private function persistAccount(string $username): CpgUser
    {
        $account = new CpgUser($username, 'hashed-password');
        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    private function registerClient(string $clientId): McpClient
    {
        $client = RegisteredMcpClient::of($clientId, 'Client de test', ['https://client.example.test/callback']);
        self::getContainer()->get(McpClientRepositoryInterface::class)->add($client);

        return $client;
    }

    private function purge(): void
    {
        $connection = $this->em->getConnection();
        TestDatabase::assertSafeToWipe($connection);
        foreach (self::TABLES as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
        $connection->executeStatement('DELETE FROM cpg_user');
        $this->em->clear();
    }

    private function lifetimes(): ConnectionLifetimes
    {
        return self::getContainer()->get(ConnectionLifetimes::class);
    }

    private function connections(): McpConnectionRepositoryInterface
    {
        return self::getContainer()->get(McpConnectionRepositoryInterface::class);
    }

    private function authorizationCodes(): McpAuthorizationCodeRepositoryInterface
    {
        return self::getContainer()->get(McpAuthorizationCodeRepositoryInterface::class);
    }
}
