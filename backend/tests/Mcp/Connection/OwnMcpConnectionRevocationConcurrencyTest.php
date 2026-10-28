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
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Process\Process;

/**
 * La révocation par la personne (#650, `DELETE /api/me/mcp-connections/{id}`)
 * croisant les deux autres écrivains de la ligne d'une connexion, en processus
 * réels : un rafraîchissement, puis le marquage de la purge.
 *
 * **Ce que la révocation prend.** Une seule instruction, la sous-requête de
 * {@see \App\Mcp\Connection\Infrastructure\Doctrine\McpConnectionRepository::revokeWhere()}
 * (`FOR NO KEY UPDATE`, par identifiant, `revoked_at IS NULL` posé deux fois),
 * sans transaction explicite ni `lockForUpdate()` : la connexion seule, jamais
 * un jeton. Un rafraîchissement (`FOR UPDATE` sur la connexion, qui y écrit le
 * dernier usage) et le marquage de la purge (`FOR UPDATE`, puis `revoked_at`)
 * la tiennent donc en même temps : la révocation **attend** leur COMMIT, puis
 * PostgreSQL réévalue `revoked_at IS NULL` sur la ligne validée (READ
 * COMMITTED, `EvalPlanQual`).
 *
 * **L'entrelacement.** Ni la révocation ni l'écriture qu'elle croise ne passent
 * par un flush de l'ORM (SQL natif), {@see \App\Tests\Support\FlushInterleaving}
 * ne s'y applique pas : la fenêtre est ouverte par un verrou que le test tient,
 * dans la transaction d'une session à part (le worker qui rafraîchit, qui
 * marque) ; le fils (Fixtures/revoke-own-mcp-connection.php, le **vrai** service
 * de révocation) **doit** s'y bloquer
 * ({@see SessionLockWait::untilBlocked()} : un fils qui finit sans attendre
 * n'a rien croisé, l'attente échoue), puis le test valide.
 *
 *  - rafraîchissement : la ligne est active quand le fils se bloque ; après le
 *    COMMIT il la révoque `person`, le dernier usage du rafraîchissement intact,
 *    une ligne d'audit ;
 *  - marquage : la ligne est active quand le fils se bloque, `expired` à
 *    l'instant du COMMIT ; le fils ne révoque rien (`revoked_at IS NULL`
 *    réévalué), constate qu'elle est à la personne, répond 204 (« done ») sans
 *    écrire ni auditer : la cause reste `expired`, l'instant du marquage aussi.
 *
 * **Mutations mesurées** (voir `.claude/rules/backend-outillage.md`, « Concurrency
 * tests ») : `SKIP LOCKED` dans la sous-requête de `revokeWhere()` rend le
 * premier test rouge (le fils ne bloque plus : il saute la ligne tenue, ne
 * révoque rien et répond « done » sur une connexion restée active) ; les deux
 * `revoked_at IS NULL` retirés rendent le second rouge (la cause réécrite
 * `person`, une seconde ligne d'audit).
 *
 * Le fils n'écrit que « done » / « not-found », le nombre de lignes d'audit ou
 * un SQLSTATE ; ses arguments sont deux UUID de lignes, aucun secret.
 */
final class OwnMcpConnectionRevocationConcurrencyTest extends WebTestCase
{
    use HoldsAnMcpConnection;
    use OpensProbeConnection;

    private const int TIMEOUT_SECONDS = 30;

    private const string CHILD_SCRIPT = __DIR__.'/Fixtures/revoke-own-mcp-connection.php';

    /** Le filet de la session du test : une attente que rien ne relâcherait échoue en 57014 au lieu de suspendre la suite. */
    private const string SAFETY_NET = "SET statement_timeout = '5s'";

    /** Un instant écrit par le « worker » du test, que la lecture du résultat compare (fixe, hors de toute horloge de test). */
    private const string WORKER_INSTANT = '2026-06-15 10:20:30+00';

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
     * Un rafraîchissement tient la connexion et y écrit le dernier usage ; la
     * personne la révoque au même instant. Elle attend le COMMIT, puis révoque :
     * la connexion est coupée `person`, le dernier usage du rafraîchissement
     * est celui qu'il a écrit (la révocation ne réécrit que `revoked_at` et la
     * cause), une ligne d'audit.
     */
    public function testARevocationCrossingARefreshRevokesTheConnection(): void
    {
        $held = $this->holdMcpConnection();
        $holder = self::openProbeBeside($this->mcpRows());

        try {
            $holder->beginTransaction();
            $holder->executeStatement(self::SAFETY_NET);
            $this->lock($holder, $held);
            // Ce que fait un rafraîchissement sous le verrou de sa connexion.
            $holder->executeStatement('UPDATE mcp_connection SET last_used_at = ?::timestamptz WHERE id = ?', [self::WORKER_INSTANT, $held->connectionId]);

            $concurrent = $this->revokeAsTheOwner($held);
            $holder->commit();
        } finally {
            $this->closeHolder($holder);
        }

        $concurrent->wait();
        $observed = $this->observed($concurrent, $held);

        self::assertNotSame(ChildPhpProcess::SETUP_FAILED, $concurrent->getExitCode(), $concurrent->getErrorOutput());
        self::assertSame(1, preg_match('/^done$/m', $concurrent->getOutput()), 'La révocation aurait dû aboutir après le COMMIT du rafraîchissement. '.$observed);
        self::assertSame(1, preg_match('/^audit 1$/m', $concurrent->getOutput()), 'Une ligne d\'audit pour la révocation. '.$observed);
        self::assertSame(0, $concurrent->getExitCode(), $observed);

        $row = $this->connectionRow($held);
        self::assertNotNull($row['revoked_at'], 'La connexion aurait dû être révoquée. '.$observed);
        self::assertSame('person', $row['revocation_cause'], $observed);
        self::assertSame(strtotime(self::WORKER_INSTANT), $row['last_used_epoch'], 'Le dernier usage du rafraîchissement aurait dû survivre. '.$observed);
    }

    /**
     * Le marquage de la purge tient la connexion échue et la révoque `expired` ;
     * la personne la révoque au même instant. Elle attend le COMMIT, ne trouve
     * plus de ligne active (`revoked_at IS NULL` réévalué sur la ligne
     * validée), constate qu'elle est à elle : 204, sans écrire ni auditer. La
     * cause reste `expired`, l'instant du marquage aussi ; aucune ligne
     * `person`.
     */
    public function testARevocationCrossingThePurgeMarkingWritesNoSecondLine(): void
    {
        $held = $this->holdMcpConnection();
        // Échue sans que la purge ait tourné : la situation que la purge marque.
        $this->alterHeldConnection($held, "granted_at = granted_at - INTERVAL '91 days'");
        $holder = self::openProbeBeside($this->mcpRows());

        try {
            $holder->beginTransaction();
            $holder->executeStatement(self::SAFETY_NET);
            $this->lock($holder, $held);
            // Ce que fait le marquage de la purge sous le verrou de la connexion.
            $holder->executeStatement(
                "UPDATE mcp_connection SET revoked_at = ?::timestamptz, revocation_cause = 'expired' WHERE id = ?",
                [self::WORKER_INSTANT, $held->connectionId],
            );

            $concurrent = $this->revokeAsTheOwner($held);
            $holder->commit();
        } finally {
            $this->closeHolder($holder);
        }

        $concurrent->wait();
        $observed = $this->observed($concurrent, $held);

        self::assertNotSame(ChildPhpProcess::SETUP_FAILED, $concurrent->getExitCode(), $concurrent->getErrorOutput());
        self::assertSame(1, preg_match('/^done$/m', $concurrent->getOutput()), 'La personne aurait dû recevoir le 204 d\'une connexion à elle. '.$observed);
        self::assertSame(1, preg_match('/^audit 0$/m', $concurrent->getOutput()), 'Aucune ligne d\'audit : la purge a déjà coupé la connexion. '.$observed);
        self::assertSame(0, $concurrent->getExitCode(), $observed);

        $row = $this->connectionRow($held);
        self::assertSame('expired', $row['revocation_cause'], 'La première révocation garde sa cause. '.$observed);
        self::assertSame(strtotime(self::WORKER_INSTANT), $row['revoked_epoch'], 'La première révocation garde son instant. '.$observed);
    }

    /**
     * Démarre le fils (la personne qui révoque sa connexion) et attend que sa
     * session soit **bloquée sur un verrou** : il a atteint la ligne que le
     * test tient. Un fils qui finit sans s'être bloqué n'a rien croisé — c'est
     * l'attente qui échoue, pas un assert plus loin.
     */
    private function revokeAsTheOwner(HeldMcpConnection $held): Process
    {
        $barrier = ProcessBarrier::hold('own-mcp-connection-revocation-');
        $concurrent = ChildPhpProcess::inTestEnvironment(self::TIMEOUT_SECONDS, self::CHILD_SCRIPT, $barrier->path(), $held->accountId, $held->connectionId);
        $concurrent->start();

        $childPid = $barrier->sessionPidOf($concurrent, self::TIMEOUT_SECONDS);
        $barrier->release();
        SessionLockWait::beside($this->mcpRows(), $concurrent, $childPid, self::TIMEOUT_SECONDS)->untilBlocked();

        return $concurrent;
    }

    /** La connexion, verrouillée comme le fait un rafraîchissement ou le marquage de la purge (`lockForUpdate()`, `FOR UPDATE`). */
    private function lock(Connection $holder, HeldMcpConnection $held): void
    {
        $holder->executeQuery('SELECT id FROM mcp_connection WHERE id = ? FOR UPDATE', [$held->connectionId]);
    }

    /** La transaction du test, close même sur un échec : un fils encore vivant peut finir. */
    private function closeHolder(Connection $holder): void
    {
        if ($holder->isTransactionActive()) {
            $holder->rollBack();
        }
        $holder->close();
    }

    /**
     * La ligne de la connexion, les instants en secondes depuis l'époque
     * (comparés comme des instants, jamais comme du texte, #434).
     *
     * @return array{revoked_at: mixed, revocation_cause: mixed, last_used_epoch: int|null, revoked_epoch: int|null}
     */
    private function connectionRow(HeldMcpConnection $held): array
    {
        $row = $this->mcpRows()->fetchAssociative(
            'SELECT revoked_at, revocation_cause, EXTRACT(EPOCH FROM last_used_at)::bigint AS last_used_epoch, EXTRACT(EPOCH FROM revoked_at)::bigint AS revoked_epoch FROM mcp_connection WHERE id = ?',
            [$held->connectionId],
        );
        self::assertIsArray($row);
        $lastUsed = $row['last_used_epoch'];
        $revoked = $row['revoked_epoch'];
        // Un `bigint` de pdo_pgsql arrive en entier ; autre chose serait un défaut du test.
        self::assertTrue(null === $lastUsed || \is_int($lastUsed));
        self::assertTrue(null === $revoked || \is_int($revoked));

        return [
            'revoked_at' => $row['revoked_at'],
            'revocation_cause' => $row['revocation_cause'],
            'last_used_epoch' => $lastUsed,
            'revoked_epoch' => $revoked,
        ];
    }

    /** Tout ce qui a été observé, pour chaque message d'échec : la sortie du fils et l'état de la ligne. */
    private function observed(Process $concurrent, HeldMcpConnection $held): string
    {
        $row = $this->connectionRow($held);

        return \sprintf(
            'fils : %s (sortie %s) ; cause de la connexion : %s ; révoquée : %s',
            trim($concurrent->getOutput().$concurrent->getErrorOutput()),
            var_export($concurrent->getExitCode(), true),
            var_export($row['revocation_cause'], true),
            null === $row['revoked_at'] ? 'non' : 'oui',
        );
    }
}
