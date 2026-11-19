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
 * Le retrait d'un client MCP, croisé avec le premier échange d'un nouveau code
 * du même client (#652, T13, constat T1 de la relecture) : un VRAI interblocage
 * `40P01`, entre deux processus et deux connexions PostgreSQL, absorbé par la
 * relance unique de {@see \App\Mcp\Connection\Application\McpClientAdministrator::remove()}.
 *
 * Modèle : {@see AccountWideRevocationDeadlockTest}, dont le harnais est repris tel quel.
 *
 * **Le cycle** (C1 : connexion déjà échangée du client ; C2 : connexion dont le code
 * n'est pas encore échangé ; C1 précède C2 par identifiant, UUID v7) :
 *  - l'échange du code de C2 verrouille C2 (`FOR UPDATE`), puis sa supersession veut
 *    C1 (`revokeOtherActiveOf`) ;
 *  - `remove()` verrouille le client (`findOneByIdForUpdate`), puis C1 puis C2 par
 *    identifiant (`lockActiveOfClient`, `ORDER BY id FOR UPDATE`).
 * Le test tient C1 ; les deux fils s'y bloquent ; au COMMIT, `remove()` prend C1 et
 * veut C2 que l'échange tient, alors que l'échange veut C1 : cycle.
 *
 * `deadlock_timeout` désigne la victime : la session la plus courte détecte le cycle
 * la première. Deux scénarios : `remove()` est la victime (relancé, aboutit), ou
 * l'échange l'est (annulé ; `remove()` aboutit du premier coup).
 *
 * Établi : (a) un `40P01` a eu lieu et `remove()` a été relancé une fois ; (b) il
 * aboutit — client et connexions supprimés, valeur rendue = connexions coupées
 * relues au second essai ; (c) l'audit `client-removed` est écrit une seule fois.
 */
final class McpClientRemovalDeadlockTest extends WebTestCase
{
    use HoldsAnMcpConnection;
    use OpensProbeConnection;

    private const int TIMEOUT_SECONDS = 30;

    private const string REMOVAL_SCRIPT = __DIR__.'/Fixtures/remove-mcp-client.php';

    private const string EXCHANGE_SCRIPT = __DIR__.'/Fixtures/present-at-token-endpoint.php';

    /** Le filet de la sonde du test (plus long que celui des fils). */
    private const string SAFETY_NET = "SET statement_timeout = '20s'";

    private const string VICTIM_DEADLOCK_TIMEOUT = '100ms';

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

    /** @return iterable<string, array{bool}> */
    public static function victims(): iterable
    {
        yield 'le retrait est annulé puis relancé' => [true];
        yield 'l\'échange est annulé' => [false];
    }

    #[DataProvider('victims')]
    public function testRemovingAClientCrossingTheFirstExchangeOfANewGrantIsRetriedWhenItIsTheVictim(bool $removalIsTheVictim): void
    {
        $issued = $this->exchange($this->grantedCode());
        $secondCode = $this->codeGrantedByTheSignedInAccount();
        $first = $this->heldMcpConnectionOf($issued);
        $secondId = $this->payloadOf($secondCode)['user_id'] ?? null;
        self::assertIsString($secondId);
        self::assertLessThan(0, strcmp($first->connectionId, $secondId), 'C1 doit précéder C2 par identifiant : sans cela, le cycle n\'existe pas.');
        $clientRowId = $this->mcpRows()->fetchOne('SELECT id FROM mcp_client WHERE client_id = ?', [$first->clientId]);
        self::assertIsString($clientRowId);

        [$removal, $exchange] = $this->crossRemovalAndExchange($first, $clientRowId, self::formBody($this->exchangeParameters($secondCode)), $removalIsTheVictim);

        $removalOutput = $removal->getOutput().$removal->getErrorOutput();
        $exchangeOutput = $exchange->getOutput().$exchange->getErrorOutput();
        $observed = \sprintf('retrait : %s | échange : %s', trim($removalOutput), trim($exchangeOutput));
        self::assertFalse(str_contains($exchangeOutput.$removalOutput, $secondCode), 'La sortie d\'un fils cite le code.');
        self::assertNotSame(ChildPhpProcess::SETUP_FAILED, $removal->getExitCode(), $observed);
        self::assertNotSame(ChildPhpProcess::SETUP_FAILED, $exchange->getExitCode(), $observed);

        // Le retrait aboutit dans les deux cas : l'interblocage n'a pas survécu à la relance.
        self::assertSame(0, preg_match('/^failed /m', $removalOutput), 'Le retrait a échoué : '.$observed);
        self::assertSame(0, $removal->getExitCode(), $observed);
        self::assertSame(1, preg_match('/^done$/m', $removalOutput), $observed);
        self::assertSame(1, preg_match('/^retried '.($removalIsTheVictim ? '1' : '0').'$/m', $removalOutput), 'Nombre de relances inattendu. '.$observed);
        // (c) L'audit du client : une seule ligne, quelle que soit la relance.
        self::assertSame(1, preg_match('/^clientaudit 1$/m', $removalOutput), 'Une seule ligne `mcp-client-removed`. '.$observed);

        // (b) Client et connexions supprimés (cascade) : rien ne reste.
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_client WHERE id = :id', ['id' => $clientRowId]), $observed);
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mcp_connection WHERE mcp_client_id = :id', ['id' => $clientRowId]), $observed);

        $exchangeFailed = 1 === preg_match('/^failed '.self::DEADLOCK.'$/m', $exchangeOutput);
        self::assertSame(!$removalIsTheVictim, $exchangeFailed, 'Ce n\'est pas la victime désignée qui a été annulée. '.$observed);

        if ($removalIsTheVictim) {
            // L'échange a gagné : C1 est remplacée (inactive), seule C2 est active
            // et coupée ; la valeur rendue est ce que le SECOND essai a relu.
            self::assertSame(0, $exchange->getExitCode(), $observed);
            self::assertSame(1, preg_match('/^issued 200$/m', $exchangeOutput), $observed);
            self::assertSame(1, preg_match('/^removed 1$/m', $removalOutput), 'Le second essai relit C2 seule. '.$observed);
            self::assertSame(1, preg_match('/^connectionaudit 1$/m', $removalOutput), $observed);

            return;
        }

        // Le retrait a gagné du premier coup : C1 et C2 coupées, l'échange annulé.
        self::assertSame(3, $exchange->getExitCode(), $observed);
        self::assertSame(1, preg_match('/^removed 2$/m', $removalOutput), $observed);
        self::assertSame(1, preg_match('/^connectionaudit 2$/m', $removalOutput), $observed);
    }

    /**
     * @return array{Process, Process} le retrait, puis l'échange (terminés)
     */
    private function crossRemovalAndExchange(HeldMcpConnection $first, string $clientRowId, #[SensitiveParameter] string $exchangeBody, bool $removalIsTheVictim): array
    {
        $holder = self::openProbeBeside($this->mcpRows());
        $removal = null;
        $exchange = null;

        try {
            // Le test tient C1 : les deux fils s'y bloquent.
            $holder->beginTransaction();
            $holder->executeStatement(self::SAFETY_NET);
            $holder->executeQuery('SELECT id FROM mcp_connection WHERE id = ? FOR UPDATE', [$first->connectionId]);

            // Le retrait prend le client, puis attend C1.
            $removalBarrier = ProcessBarrier::hold('mcp-client-removal-deadlock-');
            $removal = ChildPhpProcess::inTestEnvironment(self::TIMEOUT_SECONDS, self::REMOVAL_SCRIPT, $removalBarrier->path(), $clientRowId, ...$this->deadlockTimeoutOf($removalIsTheVictim));
            $removal->start();
            $this->releaseUntilBlocked($removalBarrier, $removal);

            // L'échange prend C2, puis attend C1 derrière le retrait.
            $exchangeBarrier = ProcessBarrier::hold('exchange-deadlock-');
            $exchange = ChildPhpProcess::inTestEnvironment(self::TIMEOUT_SECONDS, self::EXCHANGE_SCRIPT, $exchangeBarrier->path(), ...$this->deadlockTimeoutOf(!$removalIsTheVictim));
            $exchange->setInput($exchangeBody);
            $exchange->start();
            $this->releaseUntilBlocked($exchangeBarrier, $exchange);

            // Le COMMIT libère C1 : le retrait la prend et veut C2, l'échange tient C2 et veut C1.
            $holder->commit();
        } finally {
            if ($holder->isTransactionActive()) {
                $holder->rollBack();
            }
            $holder->close();
            $removal?->wait();
            $exchange?->wait();
        }

        return [$removal, $exchange];
    }

    /** @return list<string> */
    private function deadlockTimeoutOf(bool $isTheVictim): array
    {
        return $isTheVictim ? [self::VICTIM_DEADLOCK_TIMEOUT] : [];
    }

    private function releaseUntilBlocked(ProcessBarrier $barrier, Process $child): void
    {
        $childPid = $barrier->sessionPidOf($child, self::TIMEOUT_SECONDS);
        $barrier->release();
        SessionLockWait::beside($this->mcpRows(), $child, $childPid, self::TIMEOUT_SECONDS)->untilBlocked();
    }
}
