<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Presentation\ApiResource;

use ApiPlatform\Metadata\GetCollection;
use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use App\Mcp\Connection\Infrastructure\ApiPlatform\BackofficeMcpConnectionProvider;
use App\Mcp\Connection\Presentation\ApiResource\BackofficeMcpConnectionResource;
use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Mcp\Connection\RegisteredMcpClient;
use App\Tests\Support\HttpJson;
use App\Tests\Support\TestCredentials;
use App\Tests\Support\TestDatabase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Couvre GET /api/backoffice/mcp/connections (#652, spec 0007 T13, Task 18) :
 * la liste, en lecture seule, de toutes les connexions MCP de tous les comptes,
 * réservée ROLE_SUPER par l'`access_control` `^/api/backoffice`.
 *
 * La ressource n'a **aucun** identifiant API Platform : sans lui, le framework
 * synthétiserait une route d'élément que rien n'a décidé d'exposer.
 */
final class BackofficeMcpConnectionResourceTest extends WebTestCase
{
    use HttpJson;

    private const string SUPER_USERNAME = 'super';
    private const string TRUSTED_USERNAME = 'trusted';
    private const string SCOPE = 'nominative:read';
    private const string LIST_PATH = '/api/backoffice/mcp/connections';

    /** UUID syntaxiquement valide, absent de la base. */
    private const string SOME_ID = '01998b2e-2d2c-73f4-9f39-8f5b0c1f0a11';

    /** Les douze clés de la réponse, dans l'ordre du DTO : rien d'autre ne sort. */
    private const array EXPECTED_KEYS = [
        'id',
        'account',
        'clientId',
        'clientName',
        'clientRegistration',
        'grantedAt',
        'codeExchanged',
        'lastUsedAt',
        'expiresAt',
        'state',
        'revokedAt',
        'revocationCause',
    ];

    /** Sous-chaînes qu'aucune clé de la réponse ne doit contenir : jeton, empreinte, secret, code d'autorisation. */
    private const array FORBIDDEN_KEY_PARTS = ['token', 'hash', 'secret', 'authorization', 'password', 'scope'];

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    public function testSuperSeesEveryConnectionOfEveryAccount(): void
    {
        $client = self::createClient();
        $this->registerAccount($client, self::SUPER_USERNAME, [CpgUser::ROLE_SUPER]);
        $jane = $this->registerAccount($client, 'jane');
        $john = $this->registerAccount($client, 'john');

        $mcpClient = RegisteredMcpClient::of('https://client.example.test/metadata.json', 'Client de démonstration', ['https://client.example.test/callback']);
        $this->clients()->add($mcpClient);

        $janeConnection = McpConnection::grant($jane, $mcpClient, self::SCOPE, new DateTimeImmutable());
        $johnConnection = McpConnection::grant($john, $mcpClient, self::SCOPE, new DateTimeImmutable('-1 hour'));
        $this->connections()->add($janeConnection);
        $this->connections()->add($johnConnection);
        $this->connections()->revokeActive($johnConnection->getId(), RevocationCause::Client, new DateTimeImmutable());

        $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());
        $client->request('GET', self::LIST_PATH);

        self::assertResponseIsSuccessful();
        $rows = $this->rows($client);
        self::assertCount(2, $rows);

        $byAccount = [];
        foreach ($rows as $row) {
            self::assertIsString($row['account']);
            $byAccount[$row['account']] = $row;
        }
        self::assertEqualsCanonicalizing(['jane', 'john'], array_keys($byAccount));

        $janeRow = $byAccount['jane'];
        self::assertSame($janeConnection->getId()->toRfc4122(), $janeRow['id']);
        self::assertSame('https://client.example.test/metadata.json', $janeRow['clientId']);
        self::assertSame('Client de démonstration', $janeRow['clientName']);
        self::assertSame('preregistered', $janeRow['clientRegistration']);
        self::assertSame('active', $janeRow['state']);
        self::assertFalse($janeRow['codeExchanged']);
        self::assertSame($janeConnection->grantedAt()->format(\DATE_ATOM), $janeRow['grantedAt']);

        $johnRow = $byAccount['john'];
        self::assertSame($johnConnection->getId()->toRfc4122(), $johnRow['id']);
        self::assertSame('revoked', $johnRow['state']);
        self::assertSame(RevocationCause::Client->value, $johnRow['revocationCause']);
        self::assertIsString($johnRow['revokedAt']);
        // L'échéance reste rendue pour une connexion révoquée : constat, pas un choix de cette ressource.
        self::assertIsString($johnRow['expiresAt']);
    }

    public function testNullFieldsAreAlwaysPresent(): void
    {
        $client = self::createClient();
        $this->registerAccount($client, self::SUPER_USERNAME, [CpgUser::ROLE_SUPER]);
        $jane = $this->registerAccount($client, 'jane');
        $mcpClient = RegisteredMcpClient::of('https://client.example.test/metadata.json', 'Client de démonstration', ['https://client.example.test/callback']);
        $this->clients()->add($mcpClient);
        // Jamais utilisée, jamais révoquée : trois champs nuls.
        $this->connections()->add(McpConnection::grant($jane, $mcpClient, self::SCOPE, new DateTimeImmutable()));

        $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());
        $client->request('GET', self::LIST_PATH);

        self::assertResponseIsSuccessful();
        $rows = $this->rows($client);
        self::assertCount(1, $rows);
        $row = $rows[0];

        // Exactement les douze clés, nulles comprises (`skip_null_values: false`).
        self::assertSame(self::EXPECTED_KEYS, array_keys($row));
        self::assertNull($row['lastUsedAt']);
        self::assertNull($row['revokedAt']);
        self::assertNull($row['revocationCause']);

        // Ni jeton, ni empreinte, ni secret, ni code d'autorisation, dans une clé comme dans une valeur.
        foreach (array_keys($row) as $key) {
            foreach (self::FORBIDDEN_KEY_PARTS as $forbidden) {
                self::assertStringNotContainsStringIgnoringCase($forbidden, $key, 'Clé interdite : '.$key);
            }
        }
        self::assertStringNotContainsString(self::SCOPE, (string) $client->getResponse()->getContent());
    }

    public function testAnAccountWithoutSuperGets403AndAnonymous401(): void
    {
        $anonymous = self::createClient();
        $mcpClient = RegisteredMcpClient::of('https://client.example.test/metadata.json', 'Client de démonstration', ['https://client.example.test/callback']);
        $this->clients()->add($mcpClient);
        $this->connections()->add(McpConnection::grant($this->registerAccount($anonymous, 'holder'), $mcpClient, self::SCOPE, new DateTimeImmutable()));

        $anonymous->request('GET', self::LIST_PATH);
        self::assertResponseStatusCodeSame(401);

        self::ensureKernelShutdown();
        $trusted = self::createClient();
        $this->registerAccount($trusted, self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);
        $this->loginAs($trusted, self::TRUSTED_USERNAME, TestCredentials::plainPassword());
        $trusted->request('GET', self::LIST_PATH);
        self::assertResponseStatusCodeSame(403, 'Compte de confiance sans ROLE_SUPER.');

        // Jeton du palier de base (ROLE_USER seul).
        self::ensureKernelShutdown();
        $base = self::createClient();
        self::getContainer()->get('cache.rate_limiter')->clear();
        $base->request('POST', '/api/account/base-access', server: ['HTTP_X_REQUESTED_WITH' => 'fetch']);
        self::assertResponseIsSuccessful();
        $base->request('GET', self::LIST_PATH);
        self::assertResponseStatusCodeSame(403, 'Palier de base.');
    }

    public function testNoItemRouteIsSynthesised(): void
    {
        $client = self::createClient();
        $this->registerAccount($client, self::SUPER_USERNAME, [CpgUser::ROLE_SUPER]);
        $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        // Ni la route d'élément synthétisée (identifiant API Platform absent), ni une sous-route de la collection.
        $client->request('GET', '/api/backoffice_mcp_connections/'.self::SOME_ID);
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', self::LIST_PATH.'/'.self::SOME_ID);
        self::assertResponseStatusCodeSame(404);

        $router = self::getContainer()->get(RouterInterface::class);
        $paths = [];
        foreach ($router->getRouteCollection() as $name => $route) {
            if (str_contains($name, 'backoffice_mcp_connection') || str_contains($route->getPath(), 'backoffice_mcp_connection') || str_contains($route->getPath(), '/backoffice/mcp/connections')) {
                $paths[$name] = $route->getPath().' '.implode(',', $route->getMethods());
            }
        }
        // Une seule route : la collection, en GET.
        self::assertCount(1, $paths, 'Routes de la ressource : '.json_encode($paths));
        self::assertSame(['/api/backoffice/mcp/connections GET'], array_map(
            static fn (string $path): string => preg_replace('/\{\._format\}/', '', $path) ?? $path,
            array_values($paths),
        ));
    }

    /**
     * Le coût de la liste ne dépend pas du nombre de connexions : des lectures
     * fixes, jamais une par connexion, par compte ni par client. Mesuré sur le
     * Provider, hors authentification, pour que le compte ne soit pas celui de la garde.
     * Copie locale de la mesure de BackofficeMcpClientResourceTest ; son extraction
     * vers tests/Support est prévue à la Task 19.
     */
    public function testListingCostsTheSameWhateverTheConnectionCount(): void
    {
        self::bootKernel();
        $this->addConnections(2, 0);
        $few = $this->queriesDuring(fn (): array => $this->listing());

        $this->addConnections(6, 2);
        $many = $this->queriesDuring(fn (): array => $this->listing());

        self::assertCount(2, $few['result']);
        self::assertCount(8, $many['result']);
        self::assertSame($few['count'], $many['count'], 'Même nombre d\'instructions à 2 et à 8 connexions : pas de N+1.');
        // 7 instructions : le cadre de l'instantané (START TRANSACTION, SET TRANSACTION ISOLATION LEVEL REPEATABLE READ
        // READ ONLY, COMMIT) et quatre SELECT (vivantes, terminées par une autre cause, accords jamais échangés,
        // une page d'expirées échangées). L'interface en annonce trois : elle ne compte pas la page des expirées échangées.
        self::assertSame(7, $many['count']);
    }

    /**
     * @return list<BackofficeMcpConnectionResource>
     */
    private function listing(): array
    {
        $provider = self::getContainer()->get(BackofficeMcpConnectionProvider::class);

        return $provider->provide(new GetCollection());
    }

    /** Un compte, un client et une connexion par rang : le coût d'un N+1 ressortirait. */
    private function addConnections(int $count, int $offset): void
    {
        $registrar = self::getContainer()->get(CpgUserRegistrarInterface::class);
        for ($i = $offset; $i < $offset + $count; ++$i) {
            $account = $registrar->register('account'.$i, TestCredentials::plainPassword());
            $mcpClient = RegisteredMcpClient::of(\sprintf('https://client%d.example.test/metadata.json', $i), 'Client '.$i, ['https://client.example.test/callback']);
            $this->clients()->add($mcpClient);
            $this->connections()->add(McpConnection::grant($account, $mcpClient, self::SCOPE, new DateTimeImmutable()));
        }
        // La mesure doit relire la base, pas le cache d'identité.
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    /**
     * Exécute `$action` et compte les requêtes de la connexion DBAL du
     * conteneur de test (`doctrine.debug_data_holder`, DBAL 4). Un relevé vide
     * fait échouer le test plutôt que de laisser un zéro passer pour une preuve.
     * Copie locale de McpConnectionRepositoryTest::queriesDuring() ; son
     * extraction vers tests/Support est prévue à la Task 19.
     *
     * @template T
     *
     * @param callable(): T $action
     *
     * @return array{count: int, result: T}
     */
    private function queriesDuring(callable $action): array
    {
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        $result = $action();

        $count = \count($holder->getData()['default'] ?? []);
        self::assertGreaterThan(0, $count, 'Le relevé des requêtes est vide : le profilage DBAL est inactif, le compteur ne prouverait rien.');

        return ['count' => $count, 'result' => $result];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(KernelBrowser $client): array
    {
        $rows = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($rows);
        self::assertTrue(array_is_list($rows));
        foreach ($rows as $row) {
            self::assertIsArray($row);
        }

        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }

    /**
     * @param list<string> $roles
     */
    private function registerAccount(KernelBrowser $client, string $username, array $roles = []): CpgUser
    {
        $password = match ($username) {
            self::SUPER_USERNAME => TestCredentials::superPassword(),
            default => TestCredentials::plainPassword(),
        };

        return $client->getContainer()->get(CpgUserRegistrarInterface::class)->register($username, $password, $roles);
    }

    private function clients(): McpClientRepositoryInterface
    {
        return self::getContainer()->get(McpClientRepositoryInterface::class);
    }

    private function connections(): McpConnectionRepositoryInterface
    {
        return self::getContainer()->get(McpConnectionRepositoryInterface::class);
    }

    private function loginAs(KernelBrowser $client, string $username, string $password): void
    {
        $client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'fetch'], content: self::jsonBody([
            'username' => $username,
            'password' => $password,
        ]));
        self::assertResponseIsSuccessful();
    }

    private function purge(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        TestDatabase::assertSafeToWipe($connection);
        foreach (['mcp_refresh_token', 'mcp_access_token', 'mcp_authorization_code', 'mcp_connection', 'mcp_client', 'cpg_user'] as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
    }
}
