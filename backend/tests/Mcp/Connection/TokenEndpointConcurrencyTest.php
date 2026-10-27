<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection;

use App\Mcp\Connection\Application\TokenEndpointInterface;
use App\Tests\Mcp\Support\HoldsAnMcpConnection;
use App\Tests\Support\ChildPhpProcess;
use App\Tests\Support\FlushInterleaving;
use App\Tests\Support\ProcessBarrier;
use Doctrine\ORM\EntityManagerInterface;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Process\Process;

/**
 * Les deux courses du point de jeton, en processus réels (#649, tâche 5) :
 * deux rafraîchissements simultanés d'un même jeton, deux échanges simultanés
 * d'un même code — deux workers PHP-FPM servant le même client, qui rejoue
 * une requête avant d'avoir lu la réponse de la première.
 *
 * League lit le jeton (ou le code) avant de le révoquer ; en `READ COMMITTED`
 * chaque requête le lit non révoqué tant que l'autre n'a pas validé : c'est la
 * course de league, et chacune émettrait sa paire. Le dépôt la ferme en
 * relisant la connexion `FOR UPDATE` (`McpConnectionRepository::lockForUpdate()`)
 * puis le jeton sous ce verrou (`HINT_REFRESH`) : la seconde requête attend le
 * COMMIT de la première, relit la rotation (ou l'échange) qu'elle vient de
 * valider, et la juge comme une réutilisation — la connexion est révoquée.
 * L'entrelacement est explicite, sur le modèle de
 * SendAccountInvitationConcurrencyTest :
 *
 *  1. B (Fixtures/present-at-token-endpoint.php) démarre, lit le corps du
 *     formulaire sur son entrée standard et attend la barrière ;
 *  2. A, dans ce processus, présente le même corps à TokenEndpointInterface ;
 *     à son premier flush — le dernier usage de la connexion au
 *     rafraîchissement, le jeton d'accès à l'échange ; écrit, pas encore
 *     validé, verrou de la connexion tenu —, le test relâche la barrière ;
 *  3. le test attend que B ait **fini** ou que sa session soit **bloquée sur
 *     un verrou** PostgreSQL, puis laisse A valider ;
 *  4. une fois B terminé, le test lit sa réponse, la connexion et les lignes
 *     de jetons.
 *
 * Ce que les tests séquentiels (RefreshRotationTest, CodeReplayTest) ne
 * peuvent pas pincer : sans le verrou, B lit le jeton non révoqué pendant la
 * pause et obtient sa propre paire. **Mesuré** avec `forUpdate: false` dans
 * `lockForUpdate()` (le verrou retiré, la transaction et la relecture
 * gardées) : les deux tests rouges — B répond `issued 200` au lieu du refus,
 * la connexion reste active, et la base porte deux paires neuves (trois
 * jetons d'accès et trois rafraîchissements après le rafraîchissement, deux
 * et deux après l'échange) au lieu d'une. La mutation retirée, verts à chaque
 * passage.
 *
 * B présente le corps par son entrée standard, jamais en argument : la ligne
 * de commande se recopie dans les messages d'échec, et aucune sortie de B ne
 * cite un jeton — sa réponse émise n'est écrite que par son statut.
 */
final class TokenEndpointConcurrencyTest extends WebTestCase
{
    use HoldsAnMcpConnection;

    private const int TIMEOUT_SECONDS = 30;

    private const string CHILD_SCRIPT = __DIR__.'/Fixtures/present-at-token-endpoint.php';

    private const string FORM = 'application/x-www-form-urlencoded';

    /** Les refus de league, mot pour mot — ce que le point de jeton rend après la révocation. */
    private const string REUSE_HINT = 'Token has been revoked';
    private const string REPLAY_HINT = 'Authorization code has been revoked';

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
     * A rafraîchit `R1` et reçoit sa paire ; B, qui présentait `R1` au même
     * instant, a attendu le COMMIT de A, relu `R1` tourné, et reçoit le refus
     * d'un jeton révoqué (`invalid_grant`, « Token has been revoked ») : la
     * connexion est révoquée, cause `refresh-token-reuse`, et la base ne
     * porte qu'une paire neuve — celle de A, qui meurt avec la connexion.
     */
    public function testTwoSimultaneousRefreshesOfOneTokenRevokeTheConnection(): void
    {
        $issued = $this->exchange($this->grantedCode());
        $held = $this->heldMcpConnectionOf($issued);
        $firstRefreshToken = $this->json($issued)['refresh_token'] ?? null;
        self::assertIsString($firstRefreshToken);
        $body = self::formBody(self::refreshParameters($firstRefreshToken));

        $concurrent = $this->presentAtTheNextFlushOfThisProcess($body);
        $response = $this->presentHere($body);
        $concurrent->wait();

        self::assertSame(200, $response->getStatusCode(), 'Le rafraîchissement de A aurait dû aboutir.');
        $this->assertRefused($concurrent, self::REUSE_HINT, [$firstRefreshToken, $held->accessToken]);
        self::assertSame('refresh-token-reuse', $this->singleConnectionRevocationCause());
        self::assertSame(
            ['access' => 2, 'access actifs' => 1, 'refresh' => 2, 'refresh actifs' => 1],
            $this->tokenRowCounts(),
            'Deux rafraîchissements simultanés d\'un même jeton ont émis deux paires (#649).',
        );
    }

    /**
     * A échange le code et reçoit sa paire ; B, qui présentait le même code
     * au même instant, a attendu le COMMIT de A, relu le code révoqué par
     * l'échange, et reçoit le refus d'un code révoqué (`invalid_grant`,
     * « Authorization code has been revoked ») : la connexion est révoquée,
     * cause `code-replay`, et la base ne porte qu'une paire — celle de A, qui
     * meurt avec la connexion.
     */
    public function testTwoSimultaneousExchangesOfOneCodeRevokeTheConnection(): void
    {
        $code = $this->grantedCode();
        $body = self::formBody($this->exchangeParameters($code));

        $concurrent = $this->presentAtTheNextFlushOfThisProcess($body);
        $response = $this->presentHere($body);
        $concurrent->wait();

        self::assertSame(200, $response->getStatusCode(), 'L\'échange de A aurait dû aboutir.');
        $this->assertRefused($concurrent, self::REPLAY_HINT, [$code]);
        self::assertSame('code-replay', $this->singleConnectionRevocationCause());
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM mcp_authorization_code WHERE revoked_at IS NOT NULL'));
        self::assertSame(
            ['access' => 1, 'access actifs' => 1, 'refresh' => 1, 'refresh actifs' => 1],
            $this->tokenRowCounts(),
            'Deux échanges simultanés d\'un même code ont émis deux paires (#649).',
        );
    }

    /**
     * Démarre B avec le corps sur son entrée standard et le relâche au
     * prochain `postFlush` de ce processus ({@see FlushInterleaving}) : l'écriture
     * de A est faite, pas encore validée, le verrou de la connexion tenu.
     */
    private function presentAtTheNextFlushOfThisProcess(#[SensitiveParameter] string $body): Process
    {
        $barrier = ProcessBarrier::hold('token-endpoint-barrier-');
        $concurrent = ChildPhpProcess::inTestEnvironment(self::TIMEOUT_SECONDS, self::CHILD_SCRIPT, $barrier->path());
        $concurrent->setInput($body);
        $concurrent->start();
        FlushInterleaving::releaseAfterNextFlush(self::getContainer()->get(EntityManagerInterface::class), $barrier, $concurrent, self::TIMEOUT_SECONDS);

        return $concurrent;
    }

    /**
     * A : le corps présenté au point de jeton de ce processus, comme le
     * contrôleur le fait — en PSR-7, hors HTTP, pour que le flush de league
     * soit celui de l'EntityManager où l'écouteur est armé.
     */
    private function presentHere(#[SensitiveParameter] string $body): ResponseInterface
    {
        $request = new ServerRequest('POST', 'http://localhost/api/oauth/token', ['Content-Type' => self::FORM], $body);

        return self::getContainer()->get(TokenEndpointInterface::class)->respond($request, new Response());
    }

    /**
     * B a été refusé (sortie 1), sous `invalid_grant` et l'indication de
     * league attendue, et rien de ce qu'il a écrit ne cite un secret du test.
     *
     * @param list<string> $secrets
     */
    private function assertRefused(Process $concurrent, string $hint, array $secrets): void
    {
        $written = $concurrent->getOutput().$concurrent->getErrorOutput();
        foreach ($secrets as $secret) {
            // Un message qui ne cite pas la valeur : un échec la publierait
            // dans le journal de la CI.
            self::assertFalse(str_contains($written, $secret), 'La sortie du fils cite un secret.');
        }
        self::assertNotSame(ChildPhpProcess::SETUP_FAILED, $concurrent->getExitCode(), $concurrent->getErrorOutput());
        self::assertSame(1, $concurrent->getExitCode(), 'La requête concurrente aurait dû être refusée : '.$written);
        self::assertSame(1, preg_match('/^refused (\d+) (.+)$/m', $concurrent->getOutput(), $matches), $written);
        self::assertSame('400', $matches[1]);
        $refusal = json_decode($matches[2], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($refusal);
        self::assertSame('invalid_grant', $refusal['error'] ?? null, $matches[2]);
        self::assertSame($hint, $refusal['hint'] ?? null, $matches[2]);
    }

    /** La cause de révocation de la seule connexion du test, `null` tant qu'elle est active. */
    private function singleConnectionRevocationCause(): ?string
    {
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM mcp_connection'));
        $cause = $this->mcpRows()->fetchOne('SELECT revocation_cause FROM mcp_connection');
        self::assertTrue(null === $cause || \is_string($cause));

        return $cause;
    }

    /**
     * Les lignes de jetons, en tout et non révoquées : une paire neuve, pas
     * deux.
     *
     * @return array<string, int>
     */
    private function tokenRowCounts(): array
    {
        return [
            'access' => $this->countRows('SELECT COUNT(*) FROM mcp_access_token'),
            'access actifs' => $this->countRows('SELECT COUNT(*) FROM mcp_access_token WHERE revoked_at IS NULL'),
            'refresh' => $this->countRows('SELECT COUNT(*) FROM mcp_refresh_token'),
            'refresh actifs' => $this->countRows('SELECT COUNT(*) FROM mcp_refresh_token WHERE revoked_at IS NULL'),
        ];
    }
}
