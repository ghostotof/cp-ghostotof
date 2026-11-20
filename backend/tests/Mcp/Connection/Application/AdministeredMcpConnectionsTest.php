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

    /**
     * Quatre lectures sur une liste courte, jointures comprises : vivantes, terminées
     * par une autre cause que l'expiration, accords jamais échangés, puis une seule
     * page des expirées échangées. Aucune requête paresseuse pour le compte ni le client.
     */
    public function testListReadsInFourQueries(): void
    {
        $jane = $this->persistAccount('jane');
        $john = $this->persistAccount('john');
        $client = $this->registerClient('https://client.example.test/metadata.json');
        $this->exchangeCodeOf($this->grant($jane, $client, $this->now->sub(new DateInterval('P1D'))));
        $revoked = $this->grant($john, $client, $this->now->sub(new DateInterval('P5D')));
        $this->connections()->revokeActive($revoked->getId(), RevocationCause::Client, $this->now->sub(new DateInterval('P2D')));
        $this->grant($john, $client, $this->now->sub(new DateInterval('PT3H')));
        $this->em->clear();

        [$views, $selects] = $this->listCountingSelects();

        self::assertCount(3, $views);
        self::assertSame(4, $selects, 'Vivantes, autres terminées, jamais échangées, une page d\'expirées échangées, rien de plus.');
    }

    /**
     * S4 constat 1 : la purge marque une expirée avec **son propre instant**, qui
     * n'est pas la fin réelle. Une expirée échangée, échue depuis 70 jours mais
     * purgée hier, ne doit pas chasser une révocation de la personne d'il y a 10 jours.
     */
    public function testAPurgedExpiredConnectionIsRankedOnItsRealEndNotOnItsPurge(): void
    {
        $account = $this->persistAccount('jane');
        $client = $this->registerClient('https://client.example.test/metadata.json');
        $this->insertRevoked($account, $client, $this->now->sub(new DateInterval('P10D')), AdministeredMcpConnections::TERMINATED_LIMIT);
        $expired = $this->grantExchangedThenPurged($account, $client, $this->now->sub(new DateInterval('P100D')), $this->now->sub(new DateInterval('P1D')));
        $this->em->clear();

        $ids = $this->idsOfList();

        self::assertCount(AdministeredMcpConnections::TERMINATED_LIMIT, $ids);
        self::assertNotContains($expired->getId()->toRfc4122(), $ids, 'Sa fin réelle (J-70) est plus ancienne que toutes les révocations gardées.');
    }

    /** Le rang d'une expirée ne dépend pas de l'instant où la purge est passée. */
    public function testARankSurvivesThePurgeWhateverItsInstant(): void
    {
        $account = $this->persistAccount('jane');
        $client = $this->registerClient('https://client.example.test/metadata.json');
        $this->insertRevoked($account, $client, $this->now->sub(new DateInterval('P5D')), AdministeredMcpConnections::TERMINATED_LIMIT);
        // Fin réelle J-20 ; la purge est passée il y a une heure.
        $expired = $this->grantExchangedThenPurged($account, $client, $this->now->sub(new DateInterval('P50D')), $this->now->sub(new DateInterval('PT1H')));
        $this->em->clear();
        $withRecentPurge = $this->idsOfList();

        // Même connexion, purge passée plus tôt (toujours après sa fin réelle).
        $this->em->getConnection()->executeStatement(
            'UPDATE mcp_connection SET revoked_at = CAST(:at AS timestamptz) WHERE id = :id',
            ['at' => $this->now->sub(new DateInterval('P10D'))->format('Y-m-d\TH:i:sP'), 'id' => $expired->getId()->toRfc4122()],
        );
        $this->em->clear();
        $withOlderPurge = $this->idsOfList();

        self::assertSame($withOlderPurge, $withRecentPurge, 'Avant ou après un autre instant de purge, la liste est la même.');
        self::assertNotContains($expired->getId()->toRfc4122(), $withRecentPurge);
    }

    /**
     * Éviction : 201 expirées échangées à purge récente et fin réelle ancienne, face
     * à une révocation de la personne plus récente que toutes leurs fins. La
     * révocation reste, et la lecture passe par plus d'une page.
     */
    public function testRecentlyPurgedExpiredConnectionsDoNotEvictAMoreRecentRevocation(): void
    {
        $account = $this->persistAccount('jane');
        $client = $this->registerClient('https://client.example.test/metadata.json');
        $this->insertExchangedExpired($account, $client, $this->now->sub(new DateInterval('P100D')), $this->now->sub(new DateInterval('P1D')), AdministeredMcpConnections::EXCHANGED_PAGE_SIZE + 1);
        $person = $this->grant($account, $client, $this->now->sub(new DateInterval('P70D')));
        $this->connections()->revokeActive($person->getId(), RevocationCause::Person, $this->now->sub(new DateInterval('P60D')));
        $this->em->clear();

        [$views, $selects] = $this->listCountingSelects();

        $ids = array_map(static fn (AdministeredMcpConnectionView $view): string => $view->id->toRfc4122(), $views);
        self::assertCount(AdministeredMcpConnections::TERMINATED_LIMIT, $views);
        self::assertContains($person->getId()->toRfc4122(), $ids, 'La révocation (J-60) est plus récente que toutes les fins réelles (J-70).');
        self::assertSame(5, $selects, 'Trois lectures fixes et deux pages d\'expirées échangées (200 puis 1).');
        $grantedAt = array_map(static fn (AdministeredMcpConnectionView $view): int => $view->grantedAt->getTimestamp(), $views);
        $sorted = $grantedAt;
        rsort($sorted);
        self::assertSame($sorted, $grantedAt, 'L\'ordre d\'affichage reste l\'octroi décroissant.');
    }

    /**
     * La lecture s'arrête dès que le `revoked_at` de la page est plus ancien que la
     * 200e meilleure fin connue : une expirée ne finit jamais après sa purge. Ici
     * 450 expirées purgées il y a 20 jours face à 200 révocations d'il y a 10 jours,
     * une seule page est lue.
     */
    public function testTheKeysetReadingStopsOnceNothingCanEnterTheList(): void
    {
        $account = $this->persistAccount('jane');
        $client = $this->registerClient('https://client.example.test/metadata.json');
        $this->insertRevoked($account, $client, $this->now->sub(new DateInterval('P10D')), AdministeredMcpConnections::TERMINATED_LIMIT);
        $this->insertExchangedExpired($account, $client, $this->now->sub(new DateInterval('P100D')), $this->now->sub(new DateInterval('P20D')), 450);
        $this->em->clear();

        [$views, $selects] = $this->listCountingSelects();

        self::assertCount(AdministeredMcpConnections::TERMINATED_LIMIT, $views);
        self::assertSame(4, $selects, 'Une seule page d\'expirées échangées : la suivante ne pouvait rien apporter.');
    }

    /** À l'inverse, des purges récentes forcent la lecture de toutes les pages (200, 200, 50). */
    public function testTheKeysetReadingCrossesEveryPageWhileARowCouldStillEnter(): void
    {
        $account = $this->persistAccount('jane');
        $client = $this->registerClient('https://client.example.test/metadata.json');
        $this->insertRevoked($account, $client, $this->now->sub(new DateInterval('P10D')), AdministeredMcpConnections::TERMINATED_LIMIT);
        $this->insertExchangedExpired($account, $client, $this->now->sub(new DateInterval('P100D')), $this->now->sub(new DateInterval('P1D')), 450);
        $this->em->clear();

        [$views, $selects] = $this->listCountingSelects();

        self::assertCount(AdministeredMcpConnections::TERMINATED_LIMIT, $views);
        self::assertSame(6, $selects, 'Trois lectures fixes et trois pages d\'expirées échangées.');
    }

    /**
     * Des durées allongées après la purge (`app.mcp.lifetime.*`) repoussent l'échéance
     * recalculée d'une expirée échangée au-delà de son `revoked_at` : sa fin est alors
     * son marquage, jamais l'échéance recalculée. Ici l'inactivité passe de 30 à 60
     * jours : la connexion A (octroyée J-50, purgée J-18) aurait une échéance à J+10,
     * en tête de la liste, alors qu'elle a pris fin à J-18, avant les révocations de J-10.
     * La B (octroyée J-40, purgée J-5) finit à J-5, après elles.
     */
    public function testAnExpiredConnectionEndsAtItsMarkingWhenLengthenedLifetimesPushItsDeadlinePastIt(): void
    {
        $account = $this->persistAccount('jane');
        $client = $this->registerClient('https://client.example.test/metadata.json');
        $this->insertRevoked($account, $client, $this->now->sub(new DateInterval('P10D')), AdministeredMcpConnections::TERMINATED_LIMIT);
        $older = $this->grantExchangedThenPurged($account, $client, $this->now->sub(new DateInterval('P50D')), $this->now->sub(new DateInterval('P18D')));
        $newer = $this->grantExchangedThenPurged($account, $client, $this->now->sub(new DateInterval('P40D')), $this->now->sub(new DateInterval('P5D')));
        $this->em->clear();

        $ids = array_map(static fn (AdministeredMcpConnectionView $view): string => $view->id->toRfc4122(), $this->service($this->lengthenedLifetimes())->list());

        self::assertCount(AdministeredMcpConnections::TERMINATED_LIMIT, $ids);
        self::assertContains($newer->getId()->toRfc4122(), $ids, 'Fin réelle J-5 (son marquage) : plus récente que les révocations de J-10.');
        self::assertNotContains($older->getId()->toRfc4122(), $ids, 'Fin réelle J-18 (son marquage), pas J+10 (l\'échéance recalculée) : plus ancienne que les révocations.');
    }

    /**
     * L'arrêt anticipé reste exact avec des durées allongées : 450 expirées échangées
     * dont l'échéance recalculée est future, mais dont le marquage (J-25) est plus
     * ancien que les 200 révocations (J-10). Aucune n'entre dans la liste, et une seule
     * page est lue : le `revoked_at` de la page passe sous la 200e meilleure fin.
     */
    public function testTheKeysetReadingStaysExactWhenLengthenedLifetimesPushDeadlinesPastTheMarking(): void
    {
        $account = $this->persistAccount('jane');
        $client = $this->registerClient('https://client.example.test/metadata.json');
        $this->insertRevoked($account, $client, $this->now->sub(new DateInterval('P10D')), AdministeredMcpConnections::TERMINATED_LIMIT);
        // Octroyées vers J-50, marquées vers J-25 : valides sous les durées de la purge (30 jours d'inactivité).
        $this->insertExchangedExpired($account, $client, $this->now->sub(new DateInterval('P50D')), $this->now->sub(new DateInterval('P25D')), 450);
        $this->em->clear();

        $service = $this->service($this->lengthenedLifetimes());
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();
        $views = $service->list();
        $selects = 0;
        foreach ($holder->getData()['default'] ?? [] as $query) {
            if (\is_string($query['sql'] ?? null) && str_starts_with(ltrim($query['sql']), 'SELECT')) {
                ++$selects;
            }
        }

        self::assertCount(AdministeredMcpConnections::TERMINATED_LIMIT, $views);
        $revoked = array_filter($views, static fn (AdministeredMcpConnectionView $view): bool => RevocationCause::Expired->value !== $view->revocationCause);
        self::assertCount(AdministeredMcpConnections::TERMINATED_LIMIT, $revoked, 'Que les 200 révocations : aucune expirée ne finit après J-25.');
        self::assertSame(4, $selects, 'Une seule page d\'expirées échangées : les suivantes ne pouvaient rien apporter.');
    }

    /** Un accord jamais échangé, expiré puis purgé, se classe à octroi + délai du code, pas à sa purge. */
    public function testANeverExchangedExpiredGrantIsRankedOnItsGrantPlusTheUnexchangedDelay(): void
    {
        $account = $this->persistAccount('jane');
        $client = $this->registerClient('https://client.example.test/metadata.json');
        $this->insertRevoked($account, $client, $this->now->sub(new DateInterval('P5D')), AdministeredMcpConnections::TERMINATED_LIMIT);
        $unexchanged = $this->lifetimes()->unexchanged();
        // Fin J-20, purgée il y a une heure : sortie.
        $old = $this->grant($account, $client, $this->now->sub(new DateInterval('P20D'))->sub($unexchanged));
        // Fin J-2, purgée hier : plus récente que toutes les révocations, elle entre.
        $recent = $this->grant($account, $client, $this->now->sub(new DateInterval('P2D'))->sub($unexchanged));
        $this->connections()->revokeExpired([$old->getId()], $this->now->sub(new DateInterval('PT1H')));
        $this->connections()->revokeExpired([$recent->getId()], $this->now->sub(new DateInterval('P1D')));
        $this->em->clear();

        $ids = $this->idsOfList();

        self::assertCount(AdministeredMcpConnections::TERMINATED_LIMIT, $ids);
        self::assertContains($recent->getId()->toRfc4122(), $ids);
        self::assertNotContains($old->getId()->toRfc4122(), $ids);
    }

    /** @return list<string> */
    private function idsOfList(): array
    {
        return array_map(static fn (AdministeredMcpConnectionView $view): string => $view->id->toRfc4122(), $this->service()->list());
    }

    /**
     * La liste et le nombre de SELECT qu'elle a envoyés.
     *
     * @return array{0: list<AdministeredMcpConnectionView>, 1: int}
     */
    private function listCountingSelects(): array
    {
        $service = $this->service();
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();
        $views = $service->list();
        $selects = 0;
        foreach ($holder->getData()['default'] ?? [] as $query) {
            if (\is_string($query['sql'] ?? null) && str_starts_with(ltrim($query['sql']), 'SELECT')) {
                ++$selects;
            }
        }

        return [$views, $selects];
    }

    /** Octroie, échange le code, puis marque expirée à `$purgedAt` par la voie de la purge. */
    private function grantExchangedThenPurged(CpgUser $account, McpClient $client, DateTimeImmutable $grantedAt, DateTimeImmutable $purgedAt): McpConnection
    {
        $connection = $this->grant($account, $client, $grantedAt);
        $this->exchangeCodeOf($connection);
        $this->connections()->revokeExpired([$connection->getId()], $purgedAt);

        return $connection;
    }

    /**
     * `$count` expirées dont le code a été échangé, en une instruction : la i-ème est
     * octroyée à `$grantedBase` + i minutes et marquée expirée à `$purgedBase` + i minutes.
     */
    private function insertExchangedExpired(CpgUser $account, McpClient $client, DateTimeImmutable $grantedBase, DateTimeImmutable $purgedBase, int $count): void
    {
        $this->em->getConnection()->executeStatement(
            'INSERT INTO mcp_connection (id, scope, granted_at, code_exchanged_at, revoked_at, revocation_cause, account_id, mcp_client_id) '
            .'SELECT gen_random_uuid(), :scope, CAST(:granted AS timestamptz) + make_interval(mins => i), '
            ."CAST(:granted AS timestamptz) + make_interval(mins => i) + interval '1 minute', "
            ."CAST(:purged AS timestamptz) + make_interval(mins => i), 'expired', :account, :client "
            .'FROM generate_series(1, :count) AS i',
            [
                'scope' => self::SCOPE,
                'granted' => $grantedBase->format('Y-m-d\TH:i:sP'),
                'purged' => $purgedBase->format('Y-m-d\TH:i:sP'),
                'account' => $account->getId()->toRfc4122(),
                'client' => $client->getId()->toRfc4122(),
                'count' => $count,
            ],
        );
    }

    /** Le service, aux durées du conteneur par défaut ; `$lifetimes` pour les simuler changées depuis la purge. */
    private function service(?ConnectionLifetimes $lifetimes = null): AdministeredMcpConnections
    {
        $clock = new MockClock($this->now);
        $lifetimes ??= $this->lifetimes();

        return new AdministeredMcpConnections(
            $this->connections(),
            $clock,
            new McpConnectionStatus($lifetimes, $clock),
            $lifetimes,
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

    /** Inactivité 60 jours et plafond 120 jours, contre 30 et 90 en configuration : un allongement postérieur à la purge. */
    private function lengthenedLifetimes(): ConnectionLifetimes
    {
        return ConnectionLifetimes::fromConfiguration('PT10M', 'PT1H', 'P30D', 'P60D', 'P120D', 'PT1H');
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
