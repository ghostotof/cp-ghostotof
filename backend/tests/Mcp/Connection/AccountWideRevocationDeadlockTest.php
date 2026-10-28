<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection;

use App\Tests\Mcp\Support\HeldMcpConnection;
use App\Tests\Mcp\Support\HoldsAnMcpConnection;
use App\Tests\Support\ChildPhpProcess;
use App\Tests\Support\OpensProbeConnection;
use App\Tests\Support\ProcessBarrier;
use App\Tests\Support\SessionLockWait;
use PHPUnit\Framework\Attributes\DataProvider;
use SensitiveParameter;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Process\Process;

/**
 * L'interblocage accepté de #650 (F1 de la relecture, R4), reproduit en
 * processus réels et épinglé : **la révocation de tout le compte croise le
 * premier échange du code d'un nouvel accord**.
 *
 * Le compte A tient C1 (ancienne connexion, active, échangée) et C2 (un nouvel
 * accord au même client, son code pas encore échangé). UUID v7 : C1 < C2.
 *
 *  - le premier échange du code de C2 : league verrouille C2 `FOR UPDATE`
 *    (`AuthCodeRepository::isAuthCodeRevoked()`), émet les jetons, puis
 *    `revokeOtherActiveOf()` veut C1 (le couple compte/client, les autres
 *    connexions) ;
 *  - la révocation de tout le compte (`revokeAllActiveOfAccount()`, ici par
 *    `CpgUserAdministrator::changePassword()` ; la rétrogradation de
 *    `setSuperAdmin()` prend les mêmes verrous dans le même ordre) tient le
 *    compte puis verrouille ses connexions **par identifiant** : C1, puis C2.
 *
 * Si la révocation prend C1 pendant que l'échange tient C2, chacune attend ce
 * que l'autre tient : un cycle, que PostgreSQL rompt en annulant l'une
 * (`40P01`, sans nouvel essai : un 500 pour la requête annulée). **Ce n'est
 * pas corrigé, par décision de l'auteur** (pas de `NOWAIT`, pas de
 * verrouillage de la paire avant league) : c'est une limite écrite
 * (`.claude/rules/mcp-oauth-revocation-personne.md`), et ce test l'épingle — un
 * cycle reproduit, **exactement une** des deux transactions annulée, l'autre
 * aboutie, **les deux atomiques**.
 *
 * **L'entrelacement** (le tas n'y est pour rien : seule la file d'attente de
 * PostgreSQL ordonne les verrous). Le test tient C1 `FOR UPDATE` dans une
 * session à part ; (1) la révocation démarre et se bloque sur C1, après avoir
 * pris le compte ; (2) l'échange démarre, prend C2 et se bloque sur C1, **derrière**
 * la révocation dans la file ; (3) le test relâche C1 : la révocation, première
 * de la file, la prend, puis demande C2 — que l'échange tient — pendant que
 * l'échange attend C1. Chaque fils n'est démarré qu'une fois l'autre
 * **bloqué sur un verrou** ({@see SessionLockWait::untilBlocked()}) : un fils
 * qui finirait sans attendre n'a rien croisé, l'attente échoue.
 *
 * **La victime est désignée, pas laissée au hasard** (mesuré : sans cela, neuf
 * exécutions sur douze annulent l'échange, trois la révocation) : PostgreSQL
 * annule la session dont le minuteur d'interblocage échoit la première et qui
 * trouve le cycle. Le fils qui doit être la victime démarre avec
 * `deadlock_timeout = 100ms` (l'autre garde la seconde par défaut) — un
 * `SET` de session, permis au rôle de la suite — ; deux tests, un par victime.
 * Chacun vérifie la cohérence de ce qui reste :
 *
 *  - la **révocation** annulée : le mot de passe (et sa date) n'a pas changé,
 *    aucune connexion n'est révoquée `password-changed`, ni auditée ; l'échange
 *    a abouti (`issued 200`) et remplacé C1 (`superseded`) ;
 *  - l'**échange** annulé : aucun jeton n'a été émis pour C2, son code est
 *    toujours échangeable (non révoqué, `code_exchanged_at` nul) ; la
 *    révocation a abouti — mot de passe changé, C1 et C2 révoquées
 *    `password-changed`, deux lignes d'audit.
 *
 * Aucun secret en argument ni en sortie : le corps de l'échange (le code) passe
 * par l'entrée standard du fils, le mot de passe est tiré au hasard dans le fils
 * et n'est jamais écrit.
 *
 * **Mutations mesurées** (voir `.claude/rules/backend-outillage.md`, « Concurrency
 * tests ») : `SKIP LOCKED` ajouté à la sous-requête de `revokeWhere()` — la
 * révocation et le remplacement sautent la ligne tenue au lieu de l'attendre :
 * les deux tests rouges à l'attente (le fils « s'est arrêté sans attendre un
 * verrou »), aucun cycle ; `ORDER BY c.id DESC` — la révocation prend C2 avant
 * C1 et le cycle disparaît : les deux rouges sur « exactement une des deux
 * transactions annulée » (l'ordre croissant des identifiants est bien ce qui
 * forme ce cycle, et change ce que le test voit).
 */
final class AccountWideRevocationDeadlockTest extends WebTestCase
{
    use HoldsAnMcpConnection;
    use OpensProbeConnection;

    private const int TIMEOUT_SECONDS = 30;

    private const string REVOCATION_SCRIPT = __DIR__.'/Fixtures/change-account-password.php';

    private const string EXCHANGE_SCRIPT = __DIR__.'/Fixtures/present-at-token-endpoint.php';

    /** Le filet de la session du test : une attente que rien ne relâcherait échoue en 57014 au lieu de suspendre la suite. */
    private const string SAFETY_NET = "SET statement_timeout = '20s'";

    /** Le délai de recherche de cycle de la victime désignée : bien en deçà de la seconde par défaut de l'autre session. */
    private const string VICTIM_DEADLOCK_TIMEOUT = '100ms';

    /** SQLSTATE d'un interblocage détecté (`deadlock_detected`). */
    private const string DEADLOCK = '40P01';

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
     * @return iterable<string, array{bool}>
     */
    public static function victims(): iterable
    {
        yield 'la révocation est annulée' => [true];
        yield 'l\'échange est annulé' => [false];
    }

    #[DataProvider('victims')]
    public function testAnAccountWideRevocationCrossingTheFirstExchangeOfANewGrantAbortsExactlyOneOfThem(bool $revocationIsTheVictim): void
    {
        // C1 échangée, puis un second accord du même compte au même client (C2,
        // son code pas encore échangé) : dans cet ordre, la session du client
        // porte encore ses cookies pour le second accord.
        $issued = $this->exchange($this->grantedCode());
        $secondCode = $this->codeGrantedByTheSignedInAccount();
        $first = $this->heldMcpConnectionOf($issued);
        $secondId = $this->payloadOf($secondCode)['user_id'] ?? null;
        self::assertIsString($secondId);
        self::assertLessThan(0, strcmp($first->connectionId, $secondId), 'C1 doit précéder C2 par identifiant : sans cela, le cycle n\'existe pas.');
        $passwordBefore = $this->accountCredentials($first);

        [$revocation, $exchange] = $this->crossRevocationAndExchange($first, self::formBody($this->exchangeParameters($secondCode)), $revocationIsTheVictim);

        $revocationOutput = $revocation->getOutput().$revocation->getErrorOutput();
        $exchangeOutput = $exchange->getOutput().$exchange->getErrorOutput();
        self::assertFalse(str_contains($exchangeOutput.$revocationOutput, $secondCode), 'La sortie d\'un fils cite le code.');
        self::assertNotSame(ChildPhpProcess::SETUP_FAILED, $revocation->getExitCode(), $revocationOutput);
        self::assertNotSame(ChildPhpProcess::SETUP_FAILED, $exchange->getExitCode(), $exchangeOutput);

        $revocationFailed = 1 === preg_match('/^failed '.self::DEADLOCK.'$/m', $revocationOutput);
        $exchangeFailed = 1 === preg_match('/^failed '.self::DEADLOCK.'$/m', $exchangeOutput);
        $observed = \sprintf('révocation : %s | échange : %s', trim($revocationOutput), trim($exchangeOutput));
        // Le cycle est reproduit : une transaction annulée, l'autre aboutie — celle qu'on a désignée.
        self::assertNotSame($revocationFailed, $exchangeFailed, 'Exactement une des deux transactions aurait dû être annulée (40P01). '.$observed);
        self::assertSame($revocationIsTheVictim, $revocationFailed, 'Ce n\'est pas la victime désignée qui a été annulée. '.$observed);

        $causes = $this->mcpRows()->fetchAllKeyValue('SELECT id, revocation_cause FROM mcp_connection');
        $accountAfter = $this->accountCredentials($first);

        if ($revocationIsTheVictim) {
            $this->assertTheRevocationWasRolledBackAndTheExchangeServed($exchange, $revocation, $exchangeOutput, $revocationOutput, $passwordBefore, $accountAfter, $causes, $first, $secondId);

            return;
        }

        $this->assertTheExchangeWasRolledBackAndTheRevocationServed($exchange, $revocation, $exchangeOutput, $revocationOutput, $passwordBefore, $accountAfter, $causes, $first, $secondId);
    }

    /**
     * La révocation est la victime : rien d'elle ne survit (atomique), et
     * l'échange, qui attendait C1, l'a prise ensuite et a remplacé l'ancienne.
     *
     * @param array{password: mixed, changed: mixed}  $passwordBefore
     * @param array{password: mixed, changed: mixed}  $accountAfter
     * @param array<array-key, mixed>                 $causes
     */
    private function assertTheRevocationWasRolledBackAndTheExchangeServed(
        Process $exchange,
        Process $revocation,
        string $exchangeOutput,
        string $revocationOutput,
        array $passwordBefore,
        array $accountAfter,
        array $causes,
        HeldMcpConnection $first,
        string $secondId,
    ): void {
        $observed = $revocationOutput.' | '.$exchangeOutput;
        self::assertSame(3, $revocation->getExitCode(), $observed);
        self::assertSame(0, $exchange->getExitCode(), 'L\'échange aurait dû aboutir : '.$exchangeOutput);
        self::assertSame(1, preg_match('/^issued 200$/m', $exchangeOutput), $exchangeOutput);

        self::assertSame($passwordBefore, $accountAfter, 'La révocation annulée a laissé son mot de passe : elle n\'est plus atomique. '.$observed);
        self::assertNotContains('password-changed', $causes, 'Une connexion révoquée `password-changed` par une révocation annulée. '.$observed);
        self::assertSame('superseded', $causes[$first->connectionId] ?? null, 'L\'échange aurait dû remplacer C1. '.$observed);
        self::assertArrayHasKey($secondId, $causes);
        self::assertNull($causes[$secondId], 'C2, la connexion du code échangé, doit rester active. '.$observed);
        self::assertSame(1, $this->tokensOf($secondId, 'mcp_access_token'), 'Une paire de jetons pour C2. '.$observed);
        self::assertSame(1, $this->tokensOf($secondId, 'mcp_refresh_token'), $observed);
        self::assertStringNotContainsString('audit', $revocationOutput, 'Une ligne d\'audit pour une révocation annulée. '.$observed);
    }

    /**
     * L'échange est la victime : aucun jeton, le code toujours échangeable, la
     * connexion non notée ; la révocation a coupé les deux connexions du compte.
     *
     * @param array{password: mixed, changed: mixed}  $passwordBefore
     * @param array{password: mixed, changed: mixed}  $accountAfter
     * @param array<array-key, mixed>                 $causes
     */
    private function assertTheExchangeWasRolledBackAndTheRevocationServed(
        Process $exchange,
        Process $revocation,
        string $exchangeOutput,
        string $revocationOutput,
        array $passwordBefore,
        array $accountAfter,
        array $causes,
        HeldMcpConnection $first,
        string $secondId,
    ): void {
        $observed = $revocationOutput.' | '.$exchangeOutput;
        self::assertSame(3, $exchange->getExitCode(), $observed);
        self::assertSame(0, $revocation->getExitCode(), 'La révocation aurait dû aboutir : '.$revocationOutput);
        self::assertSame(1, preg_match('/^done$/m', $revocationOutput), $revocationOutput);
        self::assertSame(1, preg_match('/^audit 2$/m', $revocationOutput), 'Deux lignes d\'audit, C1 et C2. '.$observed);
        self::assertSame(1, preg_match('/^passwordaudit 1$/m', $revocationOutput), $observed);

        self::assertNotSame($passwordBefore['password'], $accountAfter['password'], 'Le mot de passe aurait dû changer. '.$observed);
        self::assertSame('password-changed', $causes[$first->connectionId] ?? null, 'C1 aurait dû être révoquée par le changement de mot de passe. '.$observed);
        self::assertSame('password-changed', $causes[$secondId] ?? null, 'C2 aussi : toutes les connexions actives du compte. '.$observed);
        self::assertSame(0, $this->tokensOf($secondId, 'mcp_access_token'), 'Un jeton émis par un échange annulé. '.$observed);
        self::assertSame(0, $this->tokensOf($secondId, 'mcp_refresh_token'), $observed);
        self::assertSame(
            [null],
            $this->mcpRows()->fetchFirstColumn('SELECT revoked_at FROM mcp_authorization_code WHERE connection_id = ?', [$secondId]),
            'Le code d\'un échange annulé doit rester échangeable (non révoqué). '.$observed,
        );
        self::assertNull(
            $this->mcpRows()->fetchOne('SELECT code_exchanged_at FROM mcp_connection WHERE id = ?', [$secondId]),
            'Un échange annulé a noté le premier échange. '.$observed,
        );
    }

    /**
     * Lance les deux fils dans l'ordre qui forme le cycle (voir le docblock de
     * la classe) et rend la révocation puis l'échange, terminés. La session du
     * test tient C1 jusqu'à ce que les deux fils soient bloqués ; elle est
     * close dans tous les cas, et aucun fils ne survit au test.
     *
     * @return array{Process, Process}
     */
    private function crossRevocationAndExchange(HeldMcpConnection $first, #[SensitiveParameter] string $exchangeBody, bool $revocationIsTheVictim): array
    {
        $holder = self::openProbeBeside($this->mcpRows());
        // Hors du try : le finally les attend toujours.
        $revocation = null;
        $exchange = null;

        try {
            $holder->beginTransaction();
            $holder->executeStatement(self::SAFETY_NET);
            $holder->executeQuery('SELECT id FROM mcp_connection WHERE id = ? FOR UPDATE', [$first->connectionId]);

            // 1. La révocation : le compte pris, bloquée sur C1.
            $revocationBarrier = ProcessBarrier::hold('account-wide-revocation-deadlock-');
            $revocation = ChildPhpProcess::inTestEnvironment(self::TIMEOUT_SECONDS, self::REVOCATION_SCRIPT, $revocationBarrier->path(), $first->accountId, ...$this->deadlockTimeoutOf($revocationIsTheVictim));
            $revocation->start();
            $this->releaseUntilBlocked($revocationBarrier, $revocation);

            // 2. L'échange : C2 prise, bloquée sur C1, derrière la révocation.
            $exchangeBarrier = ProcessBarrier::hold('exchange-deadlock-');
            $exchange = ChildPhpProcess::inTestEnvironment(self::TIMEOUT_SECONDS, self::EXCHANGE_SCRIPT, $exchangeBarrier->path(), ...$this->deadlockTimeoutOf(!$revocationIsTheVictim));
            $exchange->setInput($exchangeBody);
            $exchange->start();
            $this->releaseUntilBlocked($exchangeBarrier, $exchange);

            // 3. C1 relâchée : la révocation la prend la première, veut C2.
            $holder->commit();
        } finally {
            if ($holder->isTransactionActive()) {
                $holder->rollBack();
            }
            $holder->close();
            $revocation?->wait();
            $exchange?->wait();
        }

        return [$revocation, $exchange];
    }

    /**
     * L'argument `deadlock_timeout` du fils qui doit être la victime : sa
     * session cherche le cycle la première (100 ms contre la seconde par défaut
     * de l'autre) et s'annule. Rien pour l'autre.
     *
     * @return list<string>
     */
    private function deadlockTimeoutOf(bool $isTheVictim): array
    {
        return $isTheVictim ? [self::VICTIM_DEADLOCK_TIMEOUT] : [];
    }

    /** Relâche la barrière du fils et attend que sa session soit bloquée sur un verrou. */
    private function releaseUntilBlocked(ProcessBarrier $barrier, Process $child): void
    {
        $childPid = $barrier->sessionPidOf($child, self::TIMEOUT_SECONDS);
        $barrier->release();
        SessionLockWait::beside($this->mcpRows(), $child, $childPid, self::TIMEOUT_SECONDS)->untilBlocked();
    }

    /**
     * Le mot de passe stocké et sa date de changement : les deux écrits par la
     * révocation, relus tels quels pour être comparés.
     *
     * @return array{password: mixed, changed: mixed}
     */
    private function accountCredentials(HeldMcpConnection $held): array
    {
        $row = $this->mcpRows()->fetchAssociative('SELECT password, credentials_changed_at FROM cpg_user WHERE id = ?', [$held->accountId]);
        self::assertIsArray($row);

        return ['password' => $row['password'], 'changed' => $row['credentials_changed_at']];
    }

    /** Le nombre de jetons d'une table (`mcp_access_token` ou `mcp_refresh_token`) pour une connexion. */
    private function tokensOf(string $connectionId, string $table): int
    {
        return $this->countRows('SELECT COUNT(*) FROM '.$table.' WHERE connection_id = :id', ['id' => $connectionId]);
    }
}
