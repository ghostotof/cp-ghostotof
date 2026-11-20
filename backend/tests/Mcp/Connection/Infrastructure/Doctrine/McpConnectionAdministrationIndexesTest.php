<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Infrastructure\Doctrine;

use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\ExchangedExpiredCursor;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Mcp\Connection\RegisteredMcpClient;
use App\Tests\Support\TestDatabase;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Les trois lectures bornées de la liste d'administration des connexions MCP
 * (terminées, abandonnées, expirées échangées — #652, S4) lisent chacune leur
 * index partiel, dans l'ordre de leur `ORDER BY`, sans trier toute la table.
 *
 * Ce que le test prouve, et pourquoi il passe par le dépôt réel :
 *  - PostgreSQL ne retient un index partiel que s'il **prouve** que le `WHERE`
 *    de la requête implique le prédicat de l'index. Une cause passée en
 *    paramètre lié (`= $1`) ne le prouve pas dans un plan **générique** — celui
 *    qu'un processus long (le worker) finit par utiliser, après cinq exécutions
 *    d'un même énoncé préparé. `plan_cache_mode = force_generic_plan` joue ce
 *    pire cas, sans attendre cinq exécutions : la cause doit donc être un
 *    littéral dans le SQL que Doctrine envoie.
 *  - `enable_seqscan = off` et `enable_sort = off` rendent le plan déterministe
 *    sur un petit volume : sans eux, selon l'état de la table (lignes mortes
 *    laissées par les tests précédents), l'optimiseur préfère l'index de la clé
 *    étrangère suivi d'un tri, moins cher sur trois cents lignes. Sans tri
 *    possible, le seul plan qui rend l'ordre demandé est le parcours de l'index
 *    partiel : le test prouve qu'il est **utilisable** par la requête et qu'il
 *    sert son `ORDER BY`, pas qu'il est le moins coûteux sur la base de
 *    production. Cet avantage en coût se mesure à part (EXPLAIN ANALYZE du
 *    ticket) et ne se teste pas en CI.
 *  - `pg_stat_get_xact_numscans()` compte les parcours d'un index **de la
 *    transaction en cours** : aucune attente du collecteur de statistiques, aucune fuite d'un
 *    test à l'autre.
 */
final class McpConnectionAdministrationIndexesTest extends KernelTestCase
{
    private const string TERMINATED = 'idx_mcp_connection_admin_terminated';
    private const string ABANDONED = 'idx_mcp_connection_admin_abandoned';
    private const string EXCHANGED_EXPIRED = 'idx_mcp_connection_admin_exchanged_expired';

    /** Le volume semé : assez pour que les statistiques ne soient pas celles d'une table vide. */
    private const int ROWS_PER_KIND = 300;

    private EntityManagerInterface $em;

    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->db = $this->em->getConnection();

        $this->purge();
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        $this->purge();
        parent::tearDown();
    }

    public function testTerminatedListReadsItsIndexUnderAGenericPlan(): void
    {
        $this->seedConnections();
        $before = $this->inGenericPlanTransaction();

        $this->connections()->findTerminatedForAdministration(200);

        $this->assertScanned(self::TERMINATED, $before, $this->scans());
    }

    public function testAbandonedListReadsItsIndexUnderAGenericPlan(): void
    {
        $this->seedConnections();
        $before = $this->inGenericPlanTransaction();

        $this->connections()->findAbandonedForAdministration(new DateTimeImmutable('2026-10-01T00:00:00+02:00'), 200);

        $this->assertScanned(self::ABANDONED, $before, $this->scans());
    }

    public function testExchangedExpiredListReadsItsIndexUnderAGenericPlan(): void
    {
        $this->seedConnections();
        $before = $this->inGenericPlanTransaction();

        $this->connections()->findExchangedExpiredForAdministration(null, 200);

        $this->assertScanned(self::EXCHANGED_EXPIRED, $before, $this->scans());
    }

    /** La page suivante, reprise sur sa clé, lit le même index : la clé n'en fait pas sortir la requête. */
    public function testExchangedExpiredNextPageReadsItsIndexUnderAGenericPlan(): void
    {
        $this->seedConnections();
        $before = $this->inGenericPlanTransaction();

        $this->connections()->findExchangedExpiredForAdministration(
            new ExchangedExpiredCursor(new DateTimeImmutable('2026-10-15T00:00:00+02:00'), Uuid::v7()),
            200,
        );

        $this->assertScanned(self::EXCHANGED_EXPIRED, $before, $this->scans());
    }

    /**
     * Ouvre la transaction où les réglages de planification valent, et rend les
     * compteurs de parcours **avant** la lecture à mesurer.
     *
     * @return array<string, int>
     */
    private function inGenericPlanTransaction(): array
    {
        $this->db->beginTransaction();
        $this->db->executeStatement('SET LOCAL enable_seqscan = off');
        $this->db->executeStatement('SET LOCAL enable_sort = off');
        $this->db->executeStatement('SET LOCAL plan_cache_mode = force_generic_plan');
        // Statistiques fraîches sur le volume semé ; annulées avec la transaction.
        $this->db->executeStatement('ANALYZE mcp_connection');

        return $this->scans();
    }

    /**
     * @param array<string, int> $before
     * @param array<string, int> $after
     */
    private function assertScanned(string $index, array $before, array $after): void
    {
        self::assertArrayHasKey($index, $after, \sprintf('L\'index « %s » n\'existe pas sur mcp_connection.', $index));
        self::assertGreaterThan(
            $before[$index] ?? 0,
            $after[$index],
            \sprintf('La lecture n\'a pas parcouru l\'index « %s » : son plan ne prouve pas le prédicat de l\'index (cause liée en paramètre ?), ou l\'index ne sert pas son ORDER BY.', $index),
        );
    }

    /**
     * Les parcours de chaque index de `mcp_connection`, dans la transaction en cours.
     *
     * @return array<string, int>
     */
    private function scans(): array
    {
        $scans = [];
        foreach ($this->db->fetchAllAssociative('SELECT c.relname AS name, pg_stat_get_xact_numscans(c.oid) AS scans FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE i.indrelid = \'mcp_connection\'::regclass') as $row) {
            self::assertIsString($row['name']);
            self::assertIsInt($row['scans']);
            $scans[$row['name']] = $row['scans'];
        }

        return $scans;
    }

    /**
     * Quatre sortes de lignes, en nombre égal, écrites en SQL : une connexion
     * active, une terminée par le client, une abandonnée, une expirée échangée.
     * Les `CHECK` de la table tiennent (cause et fin liées, cause connue).
     */
    private function seedConnections(): void
    {
        $account = new CpgUser('jane', 'hashed-password');
        $this->em->persist($account);
        $client = RegisteredMcpClient::of('https://client.example.test/metadata.json', 'Client de test', ['https://client.example.test/callback']);
        $this->em->persist($client);
        $this->em->flush();

        $this->db->executeStatement(
            <<<'SQL'
                INSERT INTO mcp_connection (id, scope, granted_at, revoked_at, revocation_cause, code_exchanged_at, account_id, mcp_client_id)
                SELECT gen_random_uuid(), 'nominative:read',
                       TIMESTAMPTZ '2026-09-01 00:00:00+02' + (n || ' minutes')::interval,
                       CASE kind WHEN 'terminated' THEN TIMESTAMPTZ '2026-10-01 00:00:00+02' + (n || ' minutes')::interval
                                 WHEN 'expired' THEN TIMESTAMPTZ '2026-10-10 00:00:00+02' + (n || ' minutes')::interval END,
                       CASE kind WHEN 'terminated' THEN 'client' WHEN 'expired' THEN 'expired' END,
                       CASE kind WHEN 'abandoned' THEN NULL ELSE TIMESTAMPTZ '2026-09-01 00:00:00+02' + (n || ' minutes')::interval END,
                       :account, :client
                FROM unnest(ARRAY['active', 'terminated', 'abandoned', 'expired']) AS kind
                CROSS JOIN generate_series(1, :rows) AS n
                SQL,
            ['account' => $account->getId(), 'client' => $client->getId(), 'rows' => self::ROWS_PER_KIND],
            ['account' => 'uuid', 'client' => 'uuid'],
        );
    }

    private function purge(): void
    {
        TestDatabase::assertSafeToWipe($this->db);
        foreach (['mcp_refresh_token', 'mcp_access_token', 'mcp_authorization_code', 'mcp_connection', 'mcp_client', 'cpg_user'] as $table) {
            $this->db->executeStatement('DELETE FROM '.$table);
        }
        $this->em->clear();
    }

    private function connections(): McpConnectionRepositoryInterface
    {
        return self::getContainer()->get(McpConnectionRepositoryInterface::class);
    }
}
