<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection;

use App\Tests\Mcp\Support\HoldsAnMcpConnection;
use App\Tests\Support\ChildPhpProcess;
use App\Tests\Support\OpensProbeConnection;
use App\Tests\Support\ProcessBarrier;
use App\Tests\Support\SessionLockWait;
use Doctrine\DBAL\Exception\DriverException;
use SensitiveParameter;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Process\Process;

/**
 * La révocation d'un rafraîchissement croisant la purge quotidienne, en
 * processus réels (#649, B1) : une personne rend son rafraîchissement au point
 * de révocation au moment où la purge supprime son jeton d'accès.
 *
 * **L'ordre A → R.** La révocation prend la connexion C (`FOR UPDATE`), la
 * révoque, puis révoque le jeton d'accès A et le rafraîchissement R. A avant R
 * est l'ordre de qui touche les deux lignes, et ils sont trois : le grant de
 * rafraîchissement de league révoque A puis R ; la purge
 * (`McpAccessTokenRepository::deleteDead()`) supprime A, dont la clé étrangère
 * `mcp_refresh_token.access_token_id … ON DELETE SET NULL` met ensuite R à
 * jour (sa cascade) ; et la purge supprime ses jetons morts A puis R (ses
 * propres `DELETE`, depuis la relecture du lot B de #649 — R puis A laissait
 * un cycle avec une révocation sur une connexion déjà révoquée, dont les jetons
 * morts n'étaient pas encore purgés). Dans l'ordre inverse (R puis A), la
 * révocation tenait R et attendait A pendant que la purge tenait A (son
 * `DELETE`) et attendait R (la mise à NULL de la cascade) : un interblocage
 * (40P01) ; quand c'est la révocation que PostgreSQL annulait, elle sortait en
 * 500, la connexion restait active et aucune ligne d'audit n'était écrite, la
 * personne se croyant déconnectée.
 *
 * **L'entrelacement.** Le harnais {@see \App\Tests\Support\FlushInterleaving}
 * relâche un fils au prochain flush de l'ORM ; or ni la révocation ni la purge
 * ne passent par un flush Doctrine (tout est du SQL natif), et l'on ne peut pas
 * suspendre la révocation entre R et A par un écouteur de flush. La fenêtre est
 * donc ouverte par le verrou lui-même, avec les rôles inversés par rapport à
 * {@see RevocationEndpointConcurrencyTest} :
 *
 *  1. le test (le parent) tient A dans sa propre transaction, comme le `DELETE`
 *     de la purge verrouille la ligne du jeton d'accès (un `FOR UPDATE`, même
 *     conflit qu'un `UPDATE`/`DELETE`) ;
 *  2. le fils B (Fixtures/revoke-at-revocation-endpoint.php, le **vrai** code
 *     de révocation) rend le rafraîchissement ; il prend C, révoque C, puis —
 *     dans l'ancien ordre — révoque R (il prend le verrou de R) avant de buter
 *     sur A, que le parent tient : **la révocation est alors suspendue entre R
 *     et A**, non par un écouteur mais par le verrou ;
 *  3. le test attend que la session du fils soit **bloquée sur un verrou**
 *     ({@see \App\Tests\Support\SessionLockWait::untilBlocked()}, l'attente
 *     partagée avec McpConnectionPurgeLockOrderTest, qui joue l'autre côté :
 *     la vraie purge contre un test qui tient A), puis interpose la cascade
 *     de la purge : il demande R (`FOR UPDATE`), exactement ce que ferait la
 *     mise à NULL de `access_token_id` ;
 *  4. dans l'ancien ordre, le parent tient A et attend R, le fils tient R et
 *     attend A : PostgreSQL détecte le cycle et annule l'un des deux. Dans le
 *     nouvel ordre (A avant R), le fils n'a jamais pris R ; la cascade du
 *     parent l'obtient aussitôt, le parent relâche A, le fils révoque A puis R
 *     et valide.
 *
 * **Mesuré** sous l'ancien ordre (les deux `revokeByHash()` dans l'ordre R
 * puis A) : un interblocage à chaque passage, 40P01 soit sur le parent (la
 * cascade), soit sur le fils (sa révocation sort alors en « failed 40P01 » et
 * la connexion reste active, sans cause). Dans les deux cas l'un des asserts
 * du chemin vert échoue. L'ordre corrigé, vert à chaque passage.
 *
 * B présente le corps par son entrée standard, jamais en argument, et n'écrit
 * ni le corps ni le message du pilote (seulement le SQLSTATE).
 */
final class RevocationEndpointPurgeDeadlockTest extends WebTestCase
{
    use HoldsAnMcpConnection;
    use OpensProbeConnection;

    private const int TIMEOUT_SECONDS = 30;

    private const string CHILD_SCRIPT = __DIR__.'/Fixtures/revoke-at-revocation-endpoint.php';

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
     * Le fils révoque le rafraîchissement pendant que le test tient le jeton
     * d'accès puis réclame le rafraîchissement, dans l'ordre des verrous de la
     * purge (A puis R). Avec la révocation prenant A avant R, aucun cycle : le
     * fils bute sur A, le test obtient R, relâche A, le fils révoque la
     * connexion (cause `client`, le rafraîchissement n'est pas tourné) et
     * valide.
     */
    public function testARevocationCrossingThePurgeOfItsAccessTokenRevokesTheConnection(): void
    {
        $issued = $this->exchange($this->grantedCode());
        $held = $this->heldMcpConnectionOf($issued);
        $refreshToken = $this->json($issued)['refresh_token'] ?? null;
        self::assertIsString($refreshToken);

        // L'identifiant du jeton d'accès, désigné par son empreinte : le
        // rafraîchissement le référence par `access_token_id`.
        $accessTokenId = $this->mcpRows()->fetchOne(
            'SELECT id FROM mcp_access_token WHERE token_hash = ?',
            [hash('sha256', $held->accessToken)],
        );
        self::assertIsString($accessTokenId);

        $holder = self::openProbeBeside($this->mcpRows());
        $holderSqlState = null;

        try {
            $holder->beginTransaction();
            // Un filet : une attente que rien ne relâcherait (hors interblocage)
            // échoue en 57014 plutôt que de suspendre la suite.
            $holder->executeStatement("SET statement_timeout = '5s'");
            // Le parent tient A, comme le `DELETE` de la purge verrouille la
            // ligne du jeton d'accès.
            $holder->executeQuery('SELECT id FROM mcp_access_token WHERE id = ? FOR UPDATE', [$accessTokenId]);

            $concurrent = $this->revokeWhileHoldingTheAccessToken($refreshToken);

            // La cascade de la purge : `access_token_id` remis à NULL demande
            // le verrou de R. Dans l'ancien ordre, R est tenu par le fils —
            // interblocage ; dans le nouvel ordre, R est libre.
            try {
                $holder->executeQuery('SELECT id FROM mcp_refresh_token WHERE access_token_id = ? FOR UPDATE', [$accessTokenId]);
            } catch (DriverException $failure) {
                $holderSqlState = $failure->getSQLState() ?? 'inconnu';
            }
        } finally {
            // La transaction du parent, qu'un interblocage ait ou non abouti,
            // est close ici : A est relâché, un fils encore vivant peut finir.
            if ($holder->isTransactionActive()) {
                $holder->rollBack();
            }
            $holder->close();
        }

        $concurrent->wait();

        $written = $concurrent->getOutput().$concurrent->getErrorOutput();
        foreach ([$refreshToken, $held->accessToken] as $secret) {
            // Un message qui ne cite pas la valeur : un échec la publierait
            // dans le journal de la CI.
            self::assertFalse(str_contains($written, $secret), 'La sortie du fils cite un secret.');
        }
        self::assertNotSame(ChildPhpProcess::SETUP_FAILED, $concurrent->getExitCode(), $concurrent->getErrorOutput());

        $cause = $this->mcpRows()->fetchOne('SELECT revocation_cause FROM mcp_connection');
        // Tout ce qui a été observé : sous l'ancien ordre, l'issue du fils, le
        // SQLSTATE du parent et la cause de la connexion disent ensemble quel
        // côté PostgreSQL a annulé.
        $observed = \sprintf(
            'fils : %s ; interblocage côté parent : %s ; cause de la connexion : %s',
            trim($written),
            $holderSqlState ?? 'aucun',
            var_export($cause, true),
        );

        self::assertNull($holderSqlState, 'La cascade de la purge a été annulée : interblocage avec la révocation. '.$observed);
        self::assertSame(1, preg_match('/^revoked$/m', $concurrent->getOutput()), 'La révocation aurait dû aboutir sans interblocage. '.$observed);
        self::assertSame(0, $concurrent->getExitCode(), $observed);
        self::assertSame('client', $cause, $observed);
    }

    /**
     * Démarre B avec le corps sur son entrée standard, le relâche, puis attend
     * que sa session soit bloquée sur un verrou ({@see SessionLockWait}) : le
     * fils a pris C et R (ancien ordre) ou va prendre A (nouvel ordre), et bute
     * sur A que le parent tient.
     */
    private function revokeWhileHoldingTheAccessToken(#[SensitiveParameter] string $refreshToken): Process
    {
        $barrier = ProcessBarrier::hold('revocation-purge-deadlock-');
        $concurrent = ChildPhpProcess::inTestEnvironment(self::TIMEOUT_SECONDS, self::CHILD_SCRIPT, $barrier->path());
        $concurrent->setInput(self::formBody(['token' => $refreshToken, 'client_id' => self::CLIENT_ID]));
        $concurrent->start();

        $childPid = $barrier->sessionPidOf($concurrent, self::TIMEOUT_SECONDS);
        $barrier->release();
        SessionLockWait::beside($this->mcpRows(), $concurrent, $childPid, self::TIMEOUT_SECONDS)->untilBlocked();

        return $concurrent;
    }
}
