<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Presentation\ApiResource;

use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use App\Mcp\Connection\Infrastructure\ApiPlatform\BackofficeMcpClientProvider;
use App\Mcp\Connection\Presentation\ApiResource\BackofficeMcpClientResource;
use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Mcp\Connection\Fixtures\ClaudeCodeMetadata;
use App\Tests\Mcp\Connection\RegisteredMcpClient;
use App\Tests\Support\HttpJson;
use App\Tests\Support\ReadsSecurityAuditLog;
use App\Tests\Support\RepositoryFiles;
use App\Tests\Support\TestCredentials;
use App\Tests\Support\TestDatabase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Couvre la lecture et l'enregistrement des clients MCP par le backoffice
 * (#652, spec 0007 T13) : GET /api/backoffice/mcp/clients, GET
 * /api/backoffice/mcp/clients/{id} et POST /api/backoffice/mcp/clients,
 * réservés ROLE_SUPER par l'`access_control` `^/api/backoffice`, et la garde
 * des opérations de la ressource déjà déclarées.
 */
final class BackofficeMcpClientResourceTest extends WebTestCase
{
    use HttpJson;
    use ReadsSecurityAuditLog;

    private const string SUPER_USERNAME = 'super';
    private const string TRUSTED_USERNAME = 'trusted';
    private const string SCOPE = 'nominative:read';

    /** UUID syntaxiquement valide mais absent de la base : 404 applicatif. */
    private const string UNKNOWN_ID = '01998b2e-2d2c-73f4-9f39-8f5b0c1f0a11';

    private const string PREREGISTERED_CLIENT_ID = 'https://client.example.test/metadata.json';

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    public function testSuperListsBothRegistrationsWithTheirActiveConnectionCount(): void
    {
        $client = self::createClient();
        $this->registerAccount($client, self::SUPER_USERNAME, [CpgUser::ROLE_SUPER]);

        $preregistered = RegisteredMcpClient::of(self::PREREGISTERED_CLIENT_ID, 'Client pré-enregistré', ['https://client.example.test/callback']);
        $discovered = ClaudeCodeMetadata::storedRow();
        $clients = $this->clients();
        $clients->add($preregistered);
        $clients->add($discovered);

        // Deux connexions actives et une révoquée sur le client pré-enregistré :
        // seules les actives comptent. Le client CIMD n'en a aucune : 0, pas une clé absente.
        $jane = $this->registerAccount($client, 'jane');
        $john = $this->registerAccount($client, 'john');
        $revoked = $this->registerAccount($client, 'revoked');
        $connections = $this->connections();
        $revokedConnection = McpConnection::grant($revoked, $preregistered, self::SCOPE, new DateTimeImmutable());
        foreach ([
            McpConnection::grant($jane, $preregistered, self::SCOPE, new DateTimeImmutable()),
            McpConnection::grant($john, $preregistered, self::SCOPE, new DateTimeImmutable()),
            $revokedConnection,
        ] as $connection) {
            $connections->add($connection);
        }
        $connections->revokeActive($revokedConnection->getId(), RevocationCause::Client, new DateTimeImmutable());

        $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());
        $client->request('GET', '/api/backoffice/mcp/clients');

        self::assertResponseIsSuccessful();
        $rows = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($rows);
        self::assertCount(2, $rows);

        $byClientId = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            self::assertIsString($row['clientId']);
            $byClientId[$row['clientId']] = $row;
        }

        $pre = $byClientId[self::PREREGISTERED_CLIENT_ID];
        self::assertSame($preregistered->getId()->toRfc4122(), $pre['id']);
        self::assertSame('Client pré-enregistré', $pre['name']);
        self::assertSame(['https://client.example.test/callback'], $pre['redirectUris']);
        self::assertSame('preregistered', $pre['registration']);
        self::assertSame($preregistered->registeredAt()->format(\DATE_ATOM), $pre['registeredAt']);
        self::assertSame(2, $pre['activeConnections']);

        $cimd = $byClientId[ClaudeCodeMetadata::CLIENT_ID];
        self::assertSame('cimd', $cimd['registration']);
        self::assertSame(0, $cimd['activeConnections']);
    }

    public function testGetReturnsOneClientAndUnknownIdIs404(): void
    {
        $client = self::createClient();
        $this->registerAccount($client, self::SUPER_USERNAME, [CpgUser::ROLE_SUPER]);
        $registered = RegisteredMcpClient::of(self::PREREGISTERED_CLIENT_ID, 'Client pré-enregistré', ['https://client.example.test/callback']);
        $this->clients()->add($registered);
        // Un second client, pour prouver que la lecture désigne bien le bon.
        $this->clients()->add(ClaudeCodeMetadata::storedRow());
        $this->registerAccount($client, 'jane');
        $this->connections()->add(McpConnection::grant($this->findAccount('jane'), $registered, self::SCOPE, new DateTimeImmutable()));

        $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());
        $client->request('GET', '/api/backoffice/mcp/clients/'.$registered->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        $row = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($row);
        self::assertSame($registered->getId()->toRfc4122(), $row['id']);
        self::assertSame(self::PREREGISTERED_CLIENT_ID, $row['clientId']);
        self::assertSame(1, $row['activeConnections']);

        $client->request('GET', '/api/backoffice/mcp/clients/'.self::UNKNOWN_ID);
        self::assertResponseStatusCodeSame(404);

        // Un identifiant mal formé est refusé par le routeur (Requirement::UUID), pas par le Provider.
        $client->request('GET', '/api/backoffice/mcp/clients/not-a-uuid');
        self::assertResponseStatusCodeSame(404);
    }

    public function testSuperRegistersAPreregisteredClient(): void
    {
        $client = self::createClient();
        $super = $this->registerAccount($client, self::SUPER_USERNAME, [CpgUser::ROLE_SUPER]);
        $csrf = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $client->request('POST', '/api/backoffice/mcp/clients', server: $this->jsonWrite($csrf), content: self::jsonBody([
            'clientId' => self::PREREGISTERED_CLIENT_ID,
            'name' => 'Client pré-enregistré',
            'redirectUris' => ['https://client.example.test/callback', 'http://localhost/callback'],
        ]));

        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        $stored = $this->clients()->findOneByClientId(self::PREREGISTERED_CLIENT_ID);
        self::assertNotNull($stored);
        self::assertSame($stored->getId()->toRfc4122(), $body['id']);
        self::assertSame(self::PREREGISTERED_CLIENT_ID, $body['clientId']);
        self::assertSame('Client pré-enregistré', $body['name']);
        self::assertSame(['https://client.example.test/callback', 'http://localhost/callback'], $body['redirectUris']);
        self::assertSame('preregistered', $body['registration']);
        self::assertSame($stored->registeredAt()->format(\DATE_ATOM), $body['registeredAt']);
        self::assertSame(0, $body['activeConnections']);

        // L'audit est celui d'une requête : l'acteur est celui du jeton, jamais `console`.
        $event = self::singleSecurityAuditEvent('mcp-client-registered');
        self::assertSame(self::PREREGISTERED_CLIENT_ID, $event['clientId']);
        self::assertSame('preregistered', $event['registration']);
        self::assertSame($super->getId()->toRfc4122(), $event['actor']);
    }

    public function testAnInvalidRedirectAnswers422AtItsPath(): void
    {
        $client = self::createClient();
        $this->registerAccount($client, self::SUPER_USERNAME, [CpgUser::ROLE_SUPER]);
        $csrf = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $client->request('POST', '/api/backoffice/mcp/clients', server: $this->jsonWrite($csrf), content: self::jsonBody([
            'clientId' => self::PREREGISTERED_CLIENT_ID,
            'name' => 'Client pré-enregistré',
            'redirectUris' => ['not a redirect uri'],
        ]));

        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['violations']);
        self::assertIsArray($body['violations'][0]);
        self::assertSame('redirectUris[0]', $body['violations'][0]['propertyPath']);
        // Un refus n'écrit rien et n'audite rien.
        self::assertNull($this->clients()->findOneByClientId(self::PREREGISTERED_CLIENT_ID));
        self::assertSame([], self::securityAuditEvents('mcp-client-registered'));
    }

    public function testATakenClientIdAnswers409(): void
    {
        $client = self::createClient();
        $this->registerAccount($client, self::SUPER_USERNAME, [CpgUser::ROLE_SUPER]);
        $this->clients()->add(RegisteredMcpClient::of(self::PREREGISTERED_CLIENT_ID, 'Premier', ['https://client.example.test/callback']));
        $csrf = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $client->request('POST', '/api/backoffice/mcp/clients', server: $this->jsonWrite($csrf), content: self::jsonBody([
            'clientId' => self::PREREGISTERED_CLIENT_ID,
            'name' => 'Second',
            'redirectUris' => ['https://other.example.test/callback'],
        ]));

        self::assertResponseStatusCodeSame(409);
        // Le premier client est intact, et le refus n'est pas audité comme un enregistrement.
        self::assertSame('Premier', $this->clients()->findOneByClientId(self::PREREGISTERED_CLIENT_ID)?->getName());
        self::assertSame([], self::securityAuditEvents('mcp-client-registered'));
    }

    /**
     * `McpClientRegistrar` audite **après** son `flush` (COMMIT) : un Processor
     * qui enveloppait l'appel dans une transaction ferait précéder le COMMIT
     * par l'audit, et un échec du COMMIT laisserait un enregistrement audité
     * que la base n'a pas. Le Processor n'ouvre donc aucune transaction, ne
     * touche ni l'EntityManager ni la connexion : il assemble et délègue.
     */
    public function testTheProcessorOpensNoTransactionAroundTheRegistration(): void
    {
        $source = RepositoryFiles::read(RepositoryFiles::backend('src/Mcp/Connection/Infrastructure/ApiPlatform/BackofficeMcpClientProcessor.php'));

        foreach (['beginTransaction', 'wrapInTransaction', 'TransactionInterface', 'Doctrine\\'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source, 'Le Processor ne porte aucune transaction : l\'audit suit le COMMIT du registrar.');
        }
    }

    /**
     * @return array<string, string>
     */
    private function jsonWrite(string $csrf): array
    {
        return ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrf];
    }

    /**
     * La garde est l'`access_control`, pas la ressource : un compte de
     * confiance sans ROLE_SUPER et un jeton du palier de base reçoivent 403,
     * un anonyme 401 (403 pour une écriture : la garde CSRF, qui précède le
     * pare-feu, refuse d'abord une écriture sans cookie de session).
     *
     * Seules les opérations déjà déclarées figurent ici : une route absente
     * répond 404 à tout le monde, anonyme compris, car le routeur passe avant
     * le pare-feu (constaté à la Task 16 sur Post, Put et Delete). Les
     * opérations restantes entrent dans ce fournisseur avec leur route
     * (Tasks 17B, 18). Le troisième élément est le corps d'une écriture : un
     * corps valide, pour que seul le refus de l'accès puisse répondre.
     *
     * @return iterable<string, array{string, string, array<string, mixed>|null}>
     */
    public static function operations(): iterable
    {
        yield 'GetCollection' => ['GET', '/api/backoffice/mcp/clients', null];
        yield 'Get' => ['GET', '/api/backoffice/mcp/clients/'.self::UNKNOWN_ID, null];
        yield 'Post' => ['POST', '/api/backoffice/mcp/clients', [
            'clientId' => self::PREREGISTERED_CLIENT_ID,
            'name' => 'Client pré-enregistré',
            'redirectUris' => ['https://client.example.test/callback'],
        ]];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    #[DataProvider('operations')]
    public function testEveryOperationRefusesAnAccountWithoutSuper(string $method, string $path, ?array $body): void
    {
        $content = null === $body ? null : self::jsonBody($body);
        $json = null === $body ? [] : ['CONTENT_TYPE' => 'application/json'];

        // Anonyme : 401 (403 par la garde CSRF pour une écriture).
        $anonymous = self::createClient();
        $anonymous->request($method, $path, server: $json, content: $content);
        self::assertResponseStatusCodeSame(null === $body ? 401 : 403, 'Anonyme : '.$method.' '.$path);

        // Compte de confiance sans ROLE_SUPER : 403.
        self::ensureKernelShutdown();
        $trusted = self::createClient();
        $this->registerAccount($trusted, self::TRUSTED_USERNAME, [CpgUser::ROLE_TRUSTED]);
        $csrf = $this->loginAs($trusted, self::TRUSTED_USERNAME, TestCredentials::plainPassword());
        $trusted->request($method, $path, server: [...$json, 'HTTP_X_XSRF_TOKEN' => $csrf], content: $content);
        self::assertResponseStatusCodeSame(403, 'Compte de confiance : '.$method.' '.$path);

        // Jeton du palier de base (ROLE_USER seul) : 403.
        self::ensureKernelShutdown();
        $base = self::createClient();
        self::getContainer()->get('cache.rate_limiter')->clear();
        $base->request('POST', '/api/account/base-access', server: ['HTTP_X_REQUESTED_WITH' => 'fetch']);
        self::assertResponseIsSuccessful();
        $csrfCookie = $base->getCookieJar()->get('XSRF-TOKEN');
        self::assertNotNull($csrfCookie);
        $base->request($method, $path, server: [...$json, 'HTTP_X_XSRF_TOKEN' => $csrfCookie->getValue()], content: $content);
        self::assertResponseStatusCodeSame(403, 'Palier de base : '.$method.' '.$path);

        // Un refus n'écrit rien : ni ligne, ni audit d'enregistrement.
        self::assertNull($this->clients()->findOneByClientId(self::PREREGISTERED_CLIENT_ID));
    }

    /**
     * Le coût de la liste ne dépend pas du nombre de clients : une requête
     * pour les clients, une `GROUP BY` pour les connexions actives — jamais
     * une par client. Mesuré sur le Provider, hors authentification, pour que
     * le compte ne soit pas celui de la garde.
     */
    public function testListingTakesTwoQueriesWhateverTheClientCount(): void
    {
        self::bootKernel();
        self::getContainer()->get(CpgUserRegistrarInterface::class)->register('jane', TestCredentials::plainPassword());

        $this->addClientsWithOneConnectionEach(2, 0);
        $few = $this->queriesDuring(fn (): array => $this->listing());

        $this->addClientsWithOneConnectionEach(6, 2);
        $many = $this->queriesDuring(fn (): array => $this->listing());

        self::assertCount(2, $few['result']);
        self::assertCount(8, $many['result']);
        self::assertSame(2, $few['count'], 'Deux requêtes pour 2 clients.');
        self::assertSame(2, $many['count'], 'Deux requêtes pour 8 clients : pas de N+1.');
    }

    /**
     * Le détail d'un client est une vraie lecture d'item : une requête pour le
     * client, une pour ses connexions actives — jamais la liste entière ni le
     * décompte de tous les clients. Même mesure que la liste, sur le Provider,
     * à 2 puis 8 clients : le coût ne grandit pas avec leur nombre.
     */
    public function testDetailTakesTwoQueriesWhateverTheClientCount(): void
    {
        self::bootKernel();
        self::getContainer()->get(CpgUserRegistrarInterface::class)->register('jane', TestCredentials::plainPassword());

        $this->addClientsWithOneConnectionEach(2, 0);
        $id = $this->idOfDetailedClient();
        $few = $this->queriesDuring(fn (): mixed => $this->provider()->provide(new Get(), ['id' => $id]));

        $this->addClientsWithOneConnectionEach(6, 2);
        $id = $this->idOfDetailedClient();
        $many = $this->queriesDuring(fn (): mixed => $this->provider()->provide(new Get(), ['id' => $id]));

        foreach ([$few, $many] as $read) {
            self::assertInstanceOf(BackofficeMcpClientResource::class, $read['result']);
            self::assertSame('https://client0.example.test/metadata.json', $read['result']->clientId);
            self::assertSame(1, $read['result']->activeConnections);
        }
        self::assertSame(2, $few['count'], 'Deux requêtes pour 2 clients.');
        self::assertSame(2, $many['count'], 'Deux requêtes pour 8 clients.');
        // Le nombre de requêtes ne suffit pas (la liste en coûte aussi deux) :
        // seul le client demandé a été chargé, pas les sept autres.
        $loaded = self::getContainer()->get(EntityManagerInterface::class)->getUnitOfWork()->getIdentityMap()[McpClient::class] ?? [];
        self::assertCount(1, $loaded, 'Le détail ne lit que le client demandé, pas toute la liste.');
    }

    /** Un identifiant inconnu : `null` (le 404), après la seule lecture du client — sans décompte à faire. */
    public function testDetailOfAnUnknownClientIsNullAfterOneQuery(): void
    {
        self::bootKernel();
        self::getContainer()->get(CpgUserRegistrarInterface::class)->register('jane', TestCredentials::plainPassword());
        $this->addClientsWithOneConnectionEach(2, 0);

        $read = $this->queriesDuring(fn (): mixed => $this->provider()->provide(new Get(), ['id' => self::UNKNOWN_ID]));

        self::assertNull($read['result']);
        self::assertSame(1, $read['count'], 'Un client absent coûte une requête : pas de décompte pour rien.');
    }

    /**
     * @return list<mixed>
     */
    private function listing(): array
    {
        $provider = self::getContainer()->get(BackofficeMcpClientProvider::class);
        $rows = $provider->provide(new GetCollection());
        self::assertIsArray($rows);

        return $rows;
    }

    /**
     * La clé du client mesuré, lue **avant** la mesure, puis l'unité de travail
     * vidée : le cache d'identité ne doit pas masquer une requête.
     */
    private function idOfDetailedClient(): string
    {
        $client = $this->clients()->findOneByClientId('https://client0.example.test/metadata.json');
        self::assertNotNull($client);
        $id = $client->getId()->toRfc4122();
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return $id;
    }

    private function provider(): BackofficeMcpClientProvider
    {
        return self::getContainer()->get(BackofficeMcpClientProvider::class);
    }

    private function addClientsWithOneConnectionEach(int $count, int $offset): void
    {
        // Relu à chaque appel : l'unité de travail est vidée en fin de méthode,
        // une instance gardée d'un appel à l'autre serait détachée.
        $account = $this->findAccount('jane');
        for ($i = $offset; $i < $offset + $count; ++$i) {
            $client = RegisteredMcpClient::of(\sprintf('https://client%d.example.test/metadata.json', $i), 'Client '.$i, ['https://client.example.test/callback']);
            $this->clients()->add($client);
            $this->connections()->add(McpConnection::grant($account, $client, self::SCOPE, new DateTimeImmutable()));
        }
        // Les instances ajoutées restent dans l'unité de travail : la mesure
        // doit relire la base, pas le cache d'identité.
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

    private function findAccount(string $username): CpgUser
    {
        $account = self::getContainer()->get(EntityManagerInterface::class)->getRepository(CpgUser::class)->findOneBy(['username' => $username]);
        self::assertInstanceOf(CpgUser::class, $account);

        return $account;
    }

    private function clients(): McpClientRepositoryInterface
    {
        return self::getContainer()->get(McpClientRepositoryInterface::class);
    }

    private function connections(): McpConnectionRepositoryInterface
    {
        return self::getContainer()->get(McpConnectionRepositoryInterface::class);
    }

    private function loginAs(KernelBrowser $client, string $username, string $password): string
    {
        $client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'fetch'], content: self::jsonBody([
            'username' => $username,
            'password' => $password,
        ]));
        self::assertResponseIsSuccessful();

        $csrfCookie = $client->getCookieJar()->get('XSRF-TOKEN');
        self::assertNotNull($csrfCookie);

        return $csrfCookie->getValue();
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
