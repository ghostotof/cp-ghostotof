<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Infrastructure\Doctrine;

use App\Mcp\Connection\Application\AuthenticationInstantInterface;
use App\Mcp\Connection\Application\AuthorizationCompleterInterface;
use App\Mcp\Connection\Application\AuthorizationRequestSummary;
use App\Mcp\Connection\Application\AuthorizationRequestValidatorInterface;
use App\Mcp\Connection\Application\ConsentDecision;
use App\Mcp\Connection\Application\ConsentService;
use App\Mcp\Connection\Application\OAuthEndpoints;
use App\Mcp\Connection\Application\TransactionInterface;
use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Exception\AuthorizationRequestRejectedException;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Tests\Mcp\Connection\RegisteredMcpClient;
use App\Tests\Support\ChildPhpProcess;
use App\Tests\Support\FlushInterleaving;
use App\Tests\Support\ProcessBarrier;
use App\Tests\Support\TestDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

/**
 * Un client retiré pendant que son consentement s'écrit (#652, report de T8).
 *
 * `grant()` lisait la ligne du client pré-enregistré sans la verrouiller, puis
 * insérait la connexion qui la référence. Un administrateur qui supprime le
 * client entre les deux (son DELETE valide, la connexion arrive ensuite)
 * faisait échouer l'INSERT sur la clé étrangère : une 500 à l'endroit où la
 * personne attend un refus lisible. L'entrelacement, sur le modèle de
 * AccountAdministrationConcurrencyTest :
 *
 *  1. le fils (Fixtures/delete-mcp-client.php) attend la barrière ;
 *  2. le test accorde le consentement ; au `preFlush` de l'insertion de la
 *     connexion — client lu, connexion pas encore écrite —, il relâche le fils,
 *     qui supprime le client ;
 *  3. le test attend que le fils ait **fini** ou soit **bloqué sur un verrou** ;
 *  4. l'accord termine, puis le fils.
 *
 * Sans le verrou `FOR SHARE` de la lecture, le fils termine pendant la pause et
 * l'INSERT échoue. Avec, il attend le COMMIT de l'accord : la connexion existe,
 * puis le DELETE la balaie par cascade.
 */
final class ConsentClientRemovalRaceTest extends KernelTestCase
{
    private const int TIMEOUT_SECONDS = 30;

    private const string DELETE_SCRIPT = __DIR__.'/../../Fixtures/delete-mcp-client.php';

    private const string CLIENT_ID = 'https://client.example.test/metadata.json';
    private const string REDIRECT = 'http://localhost:49152/callback';
    private const string SCOPE = 'nominative:read';

    private McpClient $client;
    private CpgUser $account;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->purge();

        $this->client = RegisteredMcpClient::of(self::CLIENT_ID, 'Client de test', ['http://localhost/callback']);
        self::getContainer()->get(McpClientRepositoryInterface::class)->add($this->client);

        $account = new CpgUser('trusted', 'hashed-password');
        $account->setRoles([CpgUser::ROLE_TRUSTED]);
        $accounts = self::getContainer()->get(CpgUserRepositoryInterface::class);
        $accounts->save($account);
        $this->account = $account;
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    public function testADeletionDuringTheGrantWaitsAndNoForeignKeyErrorEscapes(): void
    {
        $concurrent = $this->deletionReleasedBeforeTheNextFlush();

        // Aucune exception ne doit sortir : sans verrou sur le client, l'INSERT
        // de la connexion échoue sur la clé étrangère (ForeignKeyConstraintViolationException).
        $location = $this->service($this->realValidator())->decide($this->approval(), $this->account);
        $concurrent->wait();

        self::assertStringStartsWith(self::REDIRECT, $location);
        // Le sort du fils d'abord : un fils qui n'a pas pu se mettre en place
        // doit montrer sa sortie d'erreur, pas accuser le verrou.
        self::assertNotSame(ChildPhpProcess::SETUP_FAILED, $concurrent->getExitCode(), $concurrent->getErrorOutput());
        self::assertSame(0, $concurrent->getExitCode(), 'La suppression concurrente a échoué : '.$concurrent->getOutput().$concurrent->getErrorOutput());
        // La barrière écrit d'abord « ready <pid> » : seule la dernière ligne est celle du DELETE.
        self::assertStringEndsWith("deleted 1\n", $concurrent->getOutput());

        // La suppression a eu lieu après l'accord : le client est parti, et la
        // connexion neuve avec lui, par cascade.
        self::assertSame(0, $this->rowsOf('mcp_client'));
        self::assertSame(0, $this->rowsOf('mcp_connection'));
    }

    /**
     * Garde, non rouge avant le correctif : un client déjà retiré quand
     * l'accord lit sa ligne est refusé comme « plus enregistré », sans rien
     * écrire. Le validateur (league) refuserait seul un client absent : la
     * suppression est donc faite **après** sa validation, juste avant l'accord.
     */
    public function testAGrantAfterTheDeletionIsRefusedAsNoLongerRegistered(): void
    {
        $real = $this->realValidator();
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $clientRowId = $this->client->getId()->toRfc4122();
        $validator = self::createStub(AuthorizationRequestValidatorInterface::class);
        $validator->method('validate')->willReturnCallback(
            static function (ServerRequestInterface $request) use ($real, $connection, $clientRowId): AuthorizationRequestSummary {
                $summary = $real->validate($request);
                $connection->executeStatement('DELETE FROM mcp_client WHERE id = :id', ['id' => $clientRowId]);

                return $summary;
            },
        );

        try {
            $this->service($validator)->decide($this->approval(), $this->account);
            self::fail('L\'accord d\'un client retiré aurait dû être refusé.');
        } catch (AuthorizationRequestRejectedException $refusal) {
            self::assertStringContainsString('n\'est plus enregistré', $refusal->getMessage());
        }

        self::assertSame(0, $this->rowsOf('mcp_connection'), 'Un accord refusé a écrit une connexion.');
        self::assertSame(0, $this->rowsOf('mcp_authorization_code'), 'Un accord refusé a écrit un code.');
    }

    /**
     * Démarre le fils et le relâche au prochain `preFlush` ({@see FlushInterleaving}).
     */
    private function deletionReleasedBeforeTheNextFlush(): Process
    {
        $barrier = ProcessBarrier::hold('consent-client-removal-barrier-');
        $concurrent = ChildPhpProcess::inTestEnvironment(self::TIMEOUT_SECONDS, self::DELETE_SCRIPT, $barrier->path(), $this->client->getId()->toRfc4122());
        $concurrent->start();
        FlushInterleaving::releaseBeforeNextFlush(self::getContainer()->get(EntityManagerInterface::class), $barrier, $concurrent, self::TIMEOUT_SECONDS);

        return $concurrent;
    }

    private function realValidator(): AuthorizationRequestValidatorInterface
    {
        return self::getContainer()->get(AuthorizationRequestValidatorInterface::class);
    }

    /**
     * Le vrai service, câblé à la main : seul l'instant d'authentification est
     * remplacé (il lit le jeton JWT d'une requête HTTP, absent d'un test en
     * processus), et le validateur quand le test en impose un.
     */
    private function service(AuthorizationRequestValidatorInterface $validator): ConsentService
    {
        $container = self::getContainer();

        $instant = self::createStub(AuthenticationInstantInterface::class);
        $instant->method('authenticatedAt')->willReturn($container->get(ClockInterface::class)->now());
        $instant->method('credentialsVersion')->willReturn(0);

        return new ConsentService(
            $validator,
            $container->get(AuthorizationCompleterInterface::class),
            $container->get(TransactionInterface::class),
            $container->get(McpClientRepositoryInterface::class),
            $container->get(McpConnectionRepositoryInterface::class),
            $container->get(SecurityAuditLoggerInterface::class),
            $container->get(OAuthEndpoints::class),
            $container->get(ClockInterface::class),
            new Psr17Factory(),
            $instant,
        );
    }

    /**
     * L'approbation que la page enverrait pour `$this->account`.
     */
    private function approval(): ConsentDecision
    {
        $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk-test';
        $base = self::getContainer()->getParameter('app.public_api_base_url');
        self::assertIsString($base);

        return new ConsentDecision(
            responseType: 'code',
            clientId: self::CLIENT_ID,
            redirectUri: self::REDIRECT,
            scope: self::SCOPE,
            state: 'state-1',
            codeChallenge: rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            codeChallengeMethod: 'S256',
            resource: rtrim($base, '/').'/api/mcp',
            approved: true,
            shownAccountId: $this->account->getId(),
        );
    }

    private function rowsOf(string $table): int
    {
        $count = self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne('SELECT count(*) FROM '.$table);
        self::assertIsInt($count);

        return $count;
    }

    private function purge(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $entityManager->getConnection();
        TestDatabase::assertSafeToWipe($connection);
        foreach (['mcp_refresh_token', 'mcp_access_token', 'mcp_authorization_code', 'mcp_connection', 'mcp_client', 'cpg_user'] as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
        $entityManager->clear();
    }
}
