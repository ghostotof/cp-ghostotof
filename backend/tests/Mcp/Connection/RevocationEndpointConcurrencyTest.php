<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection;

use App\Mcp\Connection\Application\TokenEndpointInterface;
use App\Tests\Mcp\Support\HoldsAnMcpConnection;
use App\Tests\Support\ChildPhpProcess;
use App\Tests\Support\FlushInterleaving;
use App\Tests\Support\ProcessBarrier;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use SensitiveParameter;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Process\Process;

/**
 * La révocation d'un rafraîchissement croisant sa rotation, en processus réels
 * (#649, tâche 6) : une personne rend au point de révocation le jeton qu'un
 * autre worker PHP-FPM est en train de faire tourner au point de jeton.
 *
 * **L'ordre des verrous** est celui du point de jeton : la connexion C
 * d'abord (`lockForIssuance()`, `FOR UPDATE`), puis le jeton d'accès A et le
 * rafraîchissement R que league révoque. La révocation prend C en premier
 * elle aussi, puis révoque C, A et R. Dans l'ordre inverse (R, A, puis C), la
 * révocation tenait R et A en attendant C pendant que le rafraîchissement,
 * qui tenait C, attendait A : un interblocage (40P01), un 500 au point de
 * révocation, et quand c'est elle que PostgreSQL annulait, la connexion
 * restait active avec la paire neuve — celle qu'un voleur venait d'obtenir.
 *
 * L'entrelacement, sur le modèle de TokenEndpointConcurrencyTest :
 *
 *  1. B (Fixtures/revoke-at-revocation-endpoint.php) démarre, lit le corps
 *     de la révocation sur son entrée standard et attend la barrière ;
 *  2. A, dans ce processus, rafraîchit le même jeton au point de jeton ; à son
 *     premier flush — le dernier usage écrit sous le verrou de C, rien encore
 *     révoqué —, le test relâche la barrière ;
 *  3. le test attend que B ait **fini** ou que sa session soit **bloquée sur
 *     un verrou**, puis laisse A révoquer l'ancienne paire, émettre la neuve
 *     et valider ;
 *  4. une fois B terminé, le test lit sa sortie, la connexion et les jetons.
 *
 * **Mesuré** : dans l'ordre inverse (la mutation : les deux `revokeByHash()`
 * avant `revokeActive()`, sans `lockForIssuance()`), B attend C en tenant R et
 * A ; A bute sur A ; PostgreSQL détecte le cycle et annule B, qui sort sur
 * « failed 40P01 » — la connexion reste active, une paire neuve active en
 * base. La mutation retirée, vert à chaque passage : B attend C, relit R
 * tourné par A, et révoque la connexion pour réutilisation.
 *
 * B présente le corps par son entrée standard, jamais en argument, et
 * n'écrit ni le corps ni le message du pilote.
 */
final class RevocationEndpointConcurrencyTest extends WebTestCase
{
    use HoldsAnMcpConnection;

    private const int TIMEOUT_SECONDS = 30;

    private const string CHILD_SCRIPT = __DIR__.'/Fixtures/revoke-at-revocation-endpoint.php';

    private const string FORM = 'application/x-www-form-urlencoded';

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
     * A fait tourner `R1` et reçoit sa paire ; B, qui rendait `R1` au même
     * instant, a attendu le COMMIT de A sur le verrou de la connexion, relu
     * `R1` tourné et révoqué la connexion : un rafraîchissement déjà tourné
     * rendu sur une connexion active est le signal d'une réutilisation
     * (décision 3), cause `refresh-token-reuse`. Aucun interblocage ; la paire
     * neuve de A reste intacte en base et meurt avec la connexion.
     */
    public function testARevocationCrossingTheRotationOfItsTokenRevokesTheConnection(): void
    {
        $issued = $this->exchange($this->grantedCode());
        $held = $this->heldMcpConnectionOf($issued);
        $firstRefreshToken = $this->json($issued)['refresh_token'] ?? null;
        self::assertIsString($firstRefreshToken);

        $concurrent = $this->revokeAtTheNextFlushOfThisProcess(self::formBody(['token' => $firstRefreshToken, 'client_id' => self::CLIENT_ID]));
        $status = $this->refreshHere(self::formBody(self::refreshParameters($firstRefreshToken)));
        $concurrent->wait();

        $written = $concurrent->getOutput().$concurrent->getErrorOutput();
        foreach ([$firstRefreshToken, $held->accessToken] as $secret) {
            self::assertStringNotContainsString($secret, $written, 'La sortie du fils cite un secret.');
        }
        self::assertNotSame(ChildPhpProcess::SETUP_FAILED, $concurrent->getExitCode(), $concurrent->getErrorOutput());
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM mcp_connection'));
        $cause = $this->mcpRows()->fetchOne('SELECT revocation_cause FROM mcp_connection');
        // Tout ce qui a été observé, dans chaque message : sous la mutation,
        // l'issue de B, celle de A et l'état de la connexion disent ensemble
        // quel côté PostgreSQL a annulé.
        $observed = \sprintf('B : %s ; A : %s ; cause de la connexion : %s', trim($written), $status, var_export($cause, true));
        self::assertSame(1, preg_match('/^revoked$/m', $concurrent->getOutput()), 'La révocation concurrente aurait dû aboutir sans interblocage. '.$observed);
        self::assertSame(0, $concurrent->getExitCode(), $observed);
        self::assertSame('200', $status, 'Le rafraîchissement de A aurait dû aboutir. '.$observed);
        self::assertSame('refresh-token-reuse', $cause, $observed);
        self::assertSame(
            ['access' => 2, 'access actifs' => 1, 'refresh' => 2, 'refresh actifs' => 1],
            [
                'access' => $this->countRows('SELECT COUNT(*) FROM mcp_access_token'),
                'access actifs' => $this->countRows('SELECT COUNT(*) FROM mcp_access_token WHERE revoked_at IS NULL'),
                'refresh' => $this->countRows('SELECT COUNT(*) FROM mcp_refresh_token'),
                'refresh actifs' => $this->countRows('SELECT COUNT(*) FROM mcp_refresh_token WHERE revoked_at IS NULL'),
            ],
        );
    }

    /**
     * Démarre B avec le corps sur son entrée standard et le relâche au
     * prochain `postFlush` de ce processus ({@see FlushInterleaving}) : le
     * dernier usage est écrit, le verrou de la connexion tenu, rien validé.
     */
    private function revokeAtTheNextFlushOfThisProcess(#[SensitiveParameter] string $body): Process
    {
        $barrier = ProcessBarrier::hold('revocation-endpoint-barrier-');
        $concurrent = ChildPhpProcess::inTestEnvironment(self::TIMEOUT_SECONDS, self::CHILD_SCRIPT, $barrier->path());
        $concurrent->setInput($body);
        $concurrent->start();
        FlushInterleaving::releaseAfterNextFlush(self::getContainer()->get(EntityManagerInterface::class), $barrier, $concurrent, self::TIMEOUT_SECONDS);

        return $concurrent;
    }

    /**
     * A : le rafraîchissement présenté au point de jeton de ce processus, en
     * PSR-7, pour que le flush soit celui de l'EntityManager où l'écouteur
     * est armé. Rend le statut, ou « failed <SQLSTATE> » si la base l'a
     * refusé (c'est alors A que PostgreSQL a annulé) — jamais le message du
     * pilote.
     */
    private function refreshHere(#[SensitiveParameter] string $body): string
    {
        $request = new ServerRequest('POST', 'http://localhost/api/oauth/token', ['Content-Type' => self::FORM], $body);

        try {
            return (string) self::getContainer()->get(TokenEndpointInterface::class)->respond($request, new Response())->getStatusCode();
        } catch (DriverException $failure) {
            return 'failed '.($failure->getSQLState() ?? 'inconnu');
        }
    }
}
