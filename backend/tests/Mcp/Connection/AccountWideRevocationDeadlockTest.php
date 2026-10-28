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
 * L'interblocage de #650 (F1 de la relecture, R4), reproduit en processus
 * réels : **la révocation de tout le compte croise le premier échange du code
 * d'un nouvel accord**. Accepté en R4 sans relance, il est depuis S3 (CR4)
 * **absorbé côté révocation** : un changement de mot de passe interbloqué est
 * relancé une fois.
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
 * (`40P01`). **Deux issues, selon la victime** :
 *
 *  - la **révocation** est annulée : l'administrateur qui change un mot de
 *    passe compromis recevait un 500, le mot de passe inchangé, les connexions
 *    vivantes — la remédiation échouait en silence (CR4). `DeadlockRetry`
 *    relance maintenant **toute** la transaction verrouillée, une fois, sur un
 *    EntityManager remis à neuf : le second essai aboutit, après l'échange
 *    (qui a pris C1 dès l'annulation) ;
 *  - l'**échange** est annulé : côté league, hors périmètre, sans relance —
 *    le client OAuth reçoit un 500 du point de jeton (`server_error`), son
 *    code reste échangeable (non révoqué), il le présente à nouveau.
 *
 * Pas de `NOWAIT`, pas de verrouillage de la paire avant league : la limite est
 * écrite (`.claude/rules/mcp-oauth-revocation-personne.md`) et ce test la
 * garde.
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
 * Que le cycle ait eu lieu se prouve ainsi : côté échange, par son `40P01` ;
 * côté révocation, qui ne montre plus d'échec, par la ligne de journal de la
 * relance (`retried 1`). État final de chacun :
 *
 *  - la **révocation** annulée puis relancée : le mot de passe a changé, sa
 *    date aussi et la version des identifiants a avancé **une fois** (le
 *    premier essai, annulé, n'a rien laissé) ; l'échange, qui a gagné le
 *    cycle, a remplacé C1 (`superseded`, déjà inactive : la relance ne la
 *    révoque pas) et émis ses jetons pour C2, que la relance a révoquée
 *    `password-changed` ; **une** ligne d'audit de connexion (C2 seule) et
 *    **une** ligne `password-changed` (l'audit suit le COMMIT réussi) ;
 *  - l'**échange** annulé : aucun jeton n'a été émis pour C2, son code est
 *    toujours échangeable (non révoqué, `code_exchanged_at` nul) ; la
 *    révocation a abouti du premier coup — mot de passe changé, C1 et C2
 *    révoquées `password-changed`, deux lignes d'audit, aucune relance.
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
 * C1 et le cycle disparaît : les deux rouges (plus de `40P01` côté échange, plus
 * de relance côté révocation). Mutations de S3 : voir `DeadlockRetryTest`.
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
    public function testAnAccountWideRevocationCrossingTheFirstExchangeOfANewGrantIsRetriedWhenItIsTheVictim(bool $revocationIsTheVictim): void
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
        // Jamais d'échec de la révocation : relancée, elle aboutit ; l'échange,
        // lui, est annulé ou non selon la victime désignée.
        self::assertFalse($revocationFailed, 'La révocation interbloquée n\'a pas été absorbée par la relance. '.$observed);
        self::assertSame(!$revocationIsTheVictim, $exchangeFailed, 'Ce n\'est pas la victime désignée qui a été annulée. '.$observed);
        // Le cycle a eu lieu : la révocation l'a absorbé (une relance), ou
        // l'échange en est mort (son 40P01 ci-dessus, et aucune relance).
        self::assertSame(1, preg_match('/^retried '.($revocationIsTheVictim ? '1' : '0').'$/m', $revocationOutput), $observed);

        $causes = $this->mcpRows()->fetchAllKeyValue('SELECT id, revocation_cause FROM mcp_connection');
        $accountAfter = $this->accountCredentials($first);

        if ($revocationIsTheVictim) {
            $this->assertTheRevocationWasRetriedAndTheExchangeServed($exchange, $revocation, $exchangeOutput, $revocationOutput, $passwordBefore, $accountAfter, $causes, $first, $secondId);

            return;
        }

        $this->assertTheExchangeWasRolledBackAndTheRevocationServed($exchange, $revocation, $exchangeOutput, $revocationOutput, $passwordBefore, $accountAfter, $causes, $first, $secondId);
    }

    /**
     * La révocation est la victime, relancée une fois (S3) : rien du premier
     * essai ne survit (atomique), l'échange, qui attendait C1, l'a prise
     * ensuite et a remplacé l'ancienne, et le second essai a coupé ce qui
     * restait actif — C2 — puis écrit l'audit, une seule fois.
     *
     * @param array{password: mixed, version: mixed} $passwordBefore
     * @param array{password: mixed, version: mixed} $accountAfter
     * @param array<array-key, mixed>                                $causes
     */
    private function assertTheRevocationWasRetriedAndTheExchangeServed(
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
        self::assertSame(0, $revocation->getExitCode(), 'La révocation aurait dû aboutir au second essai : '.$revocationOutput);
        self::assertSame(1, preg_match('/^done$/m', $revocationOutput), $observed);
        self::assertSame(0, $exchange->getExitCode(), 'L\'échange aurait dû aboutir : '.$exchangeOutput);
        self::assertSame(1, preg_match('/^issued 200$/m', $exchangeOutput), $exchangeOutput);

        self::assertNotSame($passwordBefore['password'], $accountAfter['password'], 'Le mot de passe aurait dû changer au second essai. '.$observed);
        // Une seule fois : le premier essai, annulé, n'a rien laissé, et le second
        // ne rejoue pas le premier — sinon les sessions ouvertes seraient refusées deux fois
        // (et un incrément perdu en cas de course).
        self::assertSame(
            \is_int($passwordBefore['version']) ? $passwordBefore['version'] + 1 : null,
            $accountAfter['version'],
            'La version des identifiants aurait dû avancer exactement une fois. '.$observed,
        );
        // L'état final exact : C1 remplacée par l'échange gagnant (la relance ne
        // la touche pas : inactive), C2 coupée par le changement de mot de passe.
        self::assertSame('superseded', $causes[$first->connectionId] ?? null, 'L\'échange gagnant aurait dû remplacer C1. '.$observed);
        self::assertSame('password-changed', $causes[$secondId] ?? null, 'La relance aurait dû couper C2, dont l\'échange venait d\'aboutir. '.$observed);
        self::assertSame(1, preg_match('/^audit 1$/m', $revocationOutput), 'Une ligne d\'audit, C2 seule : C1 était déjà remplacée. '.$observed);
        self::assertSame(1, preg_match('/^passwordaudit 1$/m', $revocationOutput), 'Une seule ligne `password-changed`, au COMMIT du second essai. '.$observed);
    }

    /**
     * L'échange est la victime : aucun jeton, le code toujours échangeable, la
     * connexion non notée ; la révocation a coupé les deux connexions du compte.
     *
     * @param array{password: mixed, version: mixed} $passwordBefore
     * @param array{password: mixed, version: mixed} $accountAfter
     * @param array<array-key, mixed>                                $causes
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
        self::assertSame(\is_int($passwordBefore['version']) ? $passwordBefore['version'] + 1 : null, $accountAfter['version'], 'La version des identifiants aurait dû avancer une fois. '.$observed);
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
     * Le mot de passe stocké, sa date de changement et la version des
     * identifiants : les trois écrits par la révocation, relus tels quels pour
     * être comparés.
     *
     * @return array{password: mixed, version: mixed}
     */
    private function accountCredentials(HeldMcpConnection $held): array
    {
        $row = $this->mcpRows()->fetchAssociative('SELECT password, credentials_version FROM cpg_user WHERE id = ?', [$held->accountId]);
        self::assertIsArray($row);

        return ['password' => $row['password'], 'version' => $row['credentials_version']];
    }

    /** Le nombre de jetons d'une table (`mcp_access_token` ou `mcp_refresh_token`) pour une connexion. */
    private function tokensOf(string $connectionId, string $table): int
    {
        return $this->countRows('SELECT COUNT(*) FROM '.$table.' WHERE connection_id = :id', ['id' => $connectionId]);
    }
}
