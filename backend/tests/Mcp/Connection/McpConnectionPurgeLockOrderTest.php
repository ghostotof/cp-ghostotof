<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection;

use App\Tests\Mcp\Support\HeldMcpConnection;
use App\Tests\Mcp\Support\HoldsAnMcpConnection;
use App\Tests\Support\ChildPhpProcess;
use App\Tests\Support\OpensProbeConnection;
use App\Tests\Support\ProcessBarrier;
use App\Tests\Support\SessionLockWait;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Process\Process;

/**
 * L'ordre des verrous de la purge quotidienne, en processus réels (#649,
 * lot C des relectures) : la **vraie** purge (Fixtures/purge-mcp-connections.php,
 * McpConnectionPurgerInterface) tourne dans un fils pendant que le test tient,
 * dans sa propre transaction, une ligne dans l'ordre de l'autre côté — et lui
 * en demande une seconde une fois le fils bloqué.
 *
 * Deux ordres de {@see \App\Mcp\Connection\Application\McpConnectionPurger},
 * documentés et jusqu'ici pincés par aucun test (chacun inversé, la suite
 * séquentielle restait verte) :
 *
 *  1. **les connexions avant les jetons** : `deleteRevokedBefore()` (les
 *     connexions révoquées depuis plus que la rétention) avant les
 *     `deleteDead()` des jetons. L'autre côté est une suppression de compte
 *     en cascade (`cpg_user` → `mcp_connection` → ses jetons) : elle prend la
 *     ligne de connexion, puis celles des jetons. En dernier, la suppression
 *     des connexions prenait une vieille connexion révoquée après que les
 *     `DELETE` de jetons avaient pris ses jetons morts — le cycle ;
 *  2. **A avant R** parmi les jetons morts : les jetons d'accès avant les
 *     rafraîchissements, l'ordre du point de révocation (C → A → R). L'autre
 *     côté est un `/revoke` d'un rafraîchissement sur une connexion **déjà
 *     révoquée** dont les jetons morts ne sont pas encore purgés : il tient A
 *     et demande R ; la purge en R puis A tenait R et attendait A — le cycle
 *     résiduel du lot B.
 *
 * **L'entrelacement.** Ni la purge ni l'autre côté ne passent par un flush de
 * l'ORM (tout est du SQL natif), {@see \App\Tests\Support\FlushInterleaving}
 * ne peut donc pas les entrelacer ; la fenêtre est ouverte par le verrou, sur
 * le modèle de RevocationEndpointPurgeDeadlockTest (les rôles inversés : ici
 * c'est la purge qui est réelle) :
 *
 *  1. le test tient la première ligne de l'autre côté (`FOR UPDATE`, le même
 *     conflit qu'un `UPDATE`/`DELETE`) ;
 *  2. le fils purge ; dans l'ordre correct il bute sur cette ligne **avant**
 *     d'avoir pris la seconde, dans l'ordre inverse après ;
 *  3. le test attend que la session du fils soit bloquée sur un verrou
 *     ({@see SessionLockWait::untilBlocked()}), puis demande la seconde ligne
 *     (`FOR UPDATE`) ;
 *  4. dans l'ordre correct elle est libre, le test relâche, le fils finit ;
 *     dans l'ordre inverse le fils la tient : PostgreSQL détecte le cycle et
 *     annule l'un des deux (40P01) — l'un des asserts du chemin vert échoue,
 *     quel que soit le côté annulé.
 *
 * **Mesuré** sous chaque ordre inversé (la ligne `'connections' =>` déplacée
 * après les jetons ; les lignes `'accessTokens' =>` et `'refreshTokens' =>`
 * échangées) : un interblocage à chaque passage, 40P01 sur le fils (« failed
 * 40P01 », exit 3) ou sur le parent. L'ordre rétabli, vert à chaque passage.
 *
 * Le fils n'écrit que ses compteurs ou un SQLSTATE, jamais un jeton.
 */
final class McpConnectionPurgeLockOrderTest extends WebTestCase
{
    use HoldsAnMcpConnection;
    use OpensProbeConnection;

    private const int TIMEOUT_SECONDS = 30;

    private const string CHILD_SCRIPT = __DIR__.'/Fixtures/purge-mcp-connections.php';

    protected function setUp(): void
    {
        $this->setUpOAuthFlow();
    }

    protected function tearDown(): void
    {
        $this->tearDownOAuthFlow();
        parent::tearDown();
    }

    /**
     * Ordre 1. Une connexion révoquée depuis sept mois (au-delà de la rétention
     * de six mois), avec ses jetons morts encore en base. Le test tient sa
     * ligne de connexion, comme la cascade d'une suppression de compte, puis
     * demande son jeton d'accès, comme la cascade le prend ensuite. La purge,
     * qui supprime les connexions avant les jetons, bute sur la connexion sans
     * avoir touché le jeton : la cascade l'obtient, le test relâche, la purge
     * supprime la connexion — ses jetons partent par la cascade, les
     * `deleteDead()` ne trouvent plus rien.
     */
    public function testThePurgeCrossingAnAccountDeletionCascadeDeletesTheOldRevokedConnection(): void
    {
        $held = $this->holdMcpConnection();
        $this->alterHeldConnection($held, "revoked_at = now() - INTERVAL '7 months', revocation_cause = 'client'");
        $accessTokenId = $this->accessTokenIdOf($held);

        [$holderSqlState, $concurrent] = $this->purgeWhileHolding(
            [['SELECT id FROM mcp_connection WHERE id = ? FOR UPDATE', $held->connectionId]],
            ['SELECT id FROM mcp_access_token WHERE id = ? FOR UPDATE', $accessTokenId],
        );

        $observed = $this->observed($concurrent, $holderSqlState);
        self::assertNull($holderSqlState, 'La cascade de la suppression de compte a été annulée : interblocage avec la purge. '.$observed);
        self::assertSame(1, preg_match('/^purged 1 0 0 0$/m', $concurrent->getOutput()), 'La purge aurait dû supprimer la connexion seule, ses jetons par la cascade, sans interblocage. '.$observed);
        self::assertSame(0, $concurrent->getExitCode(), $observed);
        self::assertSame(0, $this->rowCount('mcp_connection'), $observed);
        self::assertSame(0, $this->rowCount('mcp_access_token'), $observed);
        self::assertSame(0, $this->rowCount('mcp_refresh_token'), $observed);
    }

    /**
     * Ordre 2. Une connexion révoquée à l'instant (gardée par la rétention),
     * ses jetons morts pas encore purgés. Le test tient la connexion puis son
     * jeton d'accès, comme le point de révocation (C → A → R), puis demande le
     * rafraîchissement. La purge, qui supprime A avant R, bute sur A sans avoir
     * touché R : le point de révocation l'obtient, le test relâche, la purge
     * supprime A (la cascade met R à NULL) puis R ; la connexion reste.
     */
    public function testThePurgeCrossingTheRevocationOfADeadRefreshTokenDeletesItsTokens(): void
    {
        $held = $this->holdMcpConnection();
        $this->alterHeldConnection($held, "revoked_at = now(), revocation_cause = 'client'");
        $accessTokenId = $this->accessTokenIdOf($held);
        $refreshTokenId = $this->mcpRows()->fetchOne('SELECT id FROM mcp_refresh_token WHERE access_token_id = ?', [$accessTokenId]);
        self::assertIsString($refreshTokenId);

        [$holderSqlState, $concurrent] = $this->purgeWhileHolding(
            [
                ['SELECT id FROM mcp_connection WHERE id = ? FOR UPDATE', $held->connectionId],
                ['SELECT id FROM mcp_access_token WHERE id = ? FOR UPDATE', $accessTokenId],
            ],
            ['SELECT id FROM mcp_refresh_token WHERE id = ? FOR UPDATE', $refreshTokenId],
        );

        $observed = $this->observed($concurrent, $holderSqlState);
        self::assertNull($holderSqlState, 'Le point de révocation a été annulé : interblocage avec la purge. '.$observed);
        self::assertSame(1, preg_match('/^purged 0 1 1 1$/m', $concurrent->getOutput()), 'La purge aurait dû supprimer le code et les deux jetons morts sans interblocage. '.$observed);
        self::assertSame(0, $concurrent->getExitCode(), $observed);
        self::assertSame(1, $this->rowCount('mcp_connection'), $observed);
        self::assertSame(0, $this->rowCount('mcp_access_token'), $observed);
        self::assertSame(0, $this->rowCount('mcp_refresh_token'), $observed);
    }

    /**
     * Tient les lignes de `$heldLocks`, dans cet ordre, dans une transaction à
     * part ; lance la vraie purge et attend que sa session soit bloquée sur un
     * verrou ; demande alors la ligne de `$thenLock`. Rend le SQLSTATE de
     * cette demande (`null` si elle a abouti) et le fils, terminé.
     *
     * La transaction est close dans tous les cas, qu'un interblocage ait ou
     * non abouti : tout est relâché, un fils encore vivant peut finir.
     *
     * @param list<array{string, string}> $heldLocks les lignes tenues, dans l'ordre de l'autre côté : requête `FOR UPDATE`, identifiant
     * @param array{string, string}       $thenLock  la ligne demandée une fois le fils bloqué : requête `FOR UPDATE`, identifiant
     *
     * @return array{string|null, Process}
     */
    private function purgeWhileHolding(array $heldLocks, array $thenLock): array
    {
        $holder = self::openProbeBeside($this->mcpRows());
        $holderSqlState = null;
        // Hors du try : le finally l'attend toujours, le fils ne survit jamais au test.
        $concurrent = null;

        try {
            $holder->beginTransaction();
            // Un filet : une attente que rien ne relâcherait (hors interblocage)
            // échoue en 57014 plutôt que de suspendre la suite.
            $holder->executeStatement("SET statement_timeout = '5s'");
            foreach ($heldLocks as [$lock, $id]) {
                $holder->executeQuery($lock, [$id]);
            }

            $concurrent = $this->startThePurge($holder);

            try {
                $holder->executeQuery($thenLock[0], [$thenLock[1]]);
            } catch (DriverException $failure) {
                $holderSqlState = $failure->getSQLState() ?? 'inconnu';
            }
        } finally {
            if ($holder->isTransactionActive()) {
                $holder->rollBack();
            }
            $holder->close();
            // Si startThePurge() a levé après avoir libéré le fils, ou si la
            // demande ci-dessus a levé autre chose qu'un DriverException, le
            // fils est attendu ici plutôt que laissé à purger pendant le teardown.
            $concurrent?->wait();
        }

        self::assertNotNull($concurrent);
        self::assertNotSame(ChildPhpProcess::SETUP_FAILED, $concurrent->getExitCode(), $concurrent->getErrorOutput());

        return [$holderSqlState, $concurrent];
    }

    /**
     * Démarre la purge, la relâche, puis attend que sa session soit bloquée
     * sur un verrou ({@see SessionLockWait::untilBlocked()}) : celui que le
     * test tient — dans l'ordre correct, avant d'avoir pris la seconde ligne.
     */
    private function startThePurge(Connection $beside): Process
    {
        $barrier = ProcessBarrier::hold('purge-lock-order-');
        $concurrent = ChildPhpProcess::inTestEnvironment(self::TIMEOUT_SECONDS, self::CHILD_SCRIPT, $barrier->path());
        $concurrent->start();

        $childPid = $barrier->sessionPidOf($concurrent, self::TIMEOUT_SECONDS);
        $barrier->release();
        SessionLockWait::beside($beside, $concurrent, $childPid, self::TIMEOUT_SECONDS)->untilBlocked();

        return $concurrent;
    }

    /** L'identifiant du jeton d'accès de la connexion, désigné par son empreinte. */
    private function accessTokenIdOf(HeldMcpConnection $held): string
    {
        $id = $this->mcpRows()->fetchOne('SELECT id FROM mcp_access_token WHERE token_hash = ?', [hash('sha256', $held->accessToken)]);
        self::assertIsString($id);

        return $id;
    }

    /**
     * Tout ce qui a été observé, pour le message d'échec : sous un ordre
     * inversé, l'issue du fils et le SQLSTATE du parent disent ensemble quel
     * côté PostgreSQL a annulé. Aucun secret : le fils n'en écrit pas.
     */
    private function observed(Process $concurrent, ?string $holderSqlState): string
    {
        return \sprintf(
            'fils : %s ; interblocage côté parent : %s',
            trim($concurrent->getOutput().$concurrent->getErrorOutput()),
            $holderSqlState ?? 'aucun',
        );
    }

    private function rowCount(string $table): int
    {
        $count = $this->mcpRows()->fetchOne('SELECT COUNT(*) FROM '.$table);
        self::assertIsInt($count);

        return $count;
    }
}
