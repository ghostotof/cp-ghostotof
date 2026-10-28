<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Infrastructure\Doctrine;

use App\Mcp\Connection\Domain\Entity\McpAccessToken;
use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\Repository\McpAccessTokenRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Mcp\Connection\RegisteredMcpClient;
use App\Tests\Support\ReadsAllChannelsLog;
use App\Tests\Support\TestDatabase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\LogRecord;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration Doctrine (base `_test`) de la relecture d'un jeton
 * d'accès par son empreinte (#648). L'authentificateur Bearer de `/api/mcp`
 * la fait à chaque requête, puis lit la connexion, son compte (rôles, UUID)
 * et son client : ADR 0006 D4 veut tout cela en **une** requête SQL, sans
 * chargement à la demande du compte ni du client derrière elle.
 */
final class McpAccessTokenRepositoryTest extends KernelTestCase
{
    use ReadsAllChannelsLog;

    /** Les tables du modèle, des feuilles vers la racine. */
    private const array TABLES = ['mcp_refresh_token', 'mcp_access_token', 'mcp_authorization_code', 'mcp_connection', 'mcp_client'];

    private const string CLIENT_ID = 'https://client.example.test/metadata.json';
    private const string AUDIENCE = 'https://example.test/api/mcp';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    /**
     * Tout ce que l'authentificateur lit vient de la requête du jeton. Le
     * compte porte un rôle stocké, pour que `getRoles()` ait une colonne à
     * lire : sur un compte non chargé, elle déclencherait sa propre requête.
     * Témoin positif : la requête comptée est bien celle du jeton, jointe au
     * compte et au client — un journal SQL muet ne peut pas rendre ce test
     * vert, ni une requête d'une autre table.
     */
    public function testOneQueryLoadsTheTokenItsConnectionItsAccountAndItsClient(): void
    {
        $account = new CpgUser('jane', 'hashed-password');
        $account->setRoles([CpgUser::ROLE_TRUSTED]);
        $this->em->persist($account);
        $this->em->flush();
        $client = RegisteredMcpClient::of(self::CLIENT_ID, 'Client de test', ['https://client.example.test/callback']);
        $this->clients()->add($client);
        $connection = McpConnection::grant($account, $client, McpConnection::SCOPE_NOMINATIVE_READ, new DateTimeImmutable());
        $this->connections()->add($connection);
        $hash = hash('sha256', 'clear-access-token');
        $this->accessTokens()->add(new McpAccessToken($connection, $hash, self::AUDIENCE, new DateTimeImmutable('+1 hour')));
        $this->em->clear();

        $queriesBefore = \count($this->selectQueries());

        $token = $this->accessTokens()->findOneByHash($hash);
        self::assertInstanceOf(McpAccessToken::class, $token);
        $roles = $token->connection()->account()->getRoles();
        $accountId = $token->connection()->account()->accountId();
        $clientId = $token->connection()->client()->getClientId();

        $queries = \array_slice($this->selectQueries(), $queriesBefore);
        self::assertCount(1, $queries, 'Le jeton, sa connexion, son compte et son client doivent venir d\'une seule requête.');
        self::assertStringContainsString('FROM mcp_access_token', $queries[0]);
        self::assertStringContainsString('cpg_user', $queries[0]);
        self::assertStringContainsString('mcp_client', $queries[0]);
        self::assertContains(CpgUser::ROLE_TRUSTED, $roles);
        self::assertSame($account->accountId(), $accountId);
        self::assertSame(self::CLIENT_ID, $clientId);
    }

    public function testAnUnknownHashFindsNothing(): void
    {
        self::assertNull($this->accessTokens()->findOneByHash(hash('sha256', 'unknown-access-token')));
    }

    /**
     * Les SELECT journalisés jusqu'ici (canal `doctrine`, `debug`, sous
     * `kernel.debug` comme en test), dans l'ordre.
     *
     * @return list<string>
     */
    private function selectQueries(): array
    {
        return array_values(array_map(
            static fn (LogRecord $record): string => $record->message,
            array_filter(
                self::allChannelsLogRecords(),
                static fn (LogRecord $record): bool => 'doctrine' === $record->channel && str_contains($record->message, 'SELECT'),
            ),
        ));
    }

    private function purge(): void
    {
        $connection = $this->em->getConnection();
        TestDatabase::assertSafeToWipe($connection);
        foreach (self::TABLES as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
        $connection->executeStatement('DELETE FROM cpg_user');
        $this->em->clear();
    }

    private function clients(): McpClientRepositoryInterface
    {
        return self::getContainer()->get(McpClientRepositoryInterface::class);
    }

    private function connections(): McpConnectionRepositoryInterface
    {
        return self::getContainer()->get(McpConnectionRepositoryInterface::class);
    }

    private function accessTokens(): McpAccessTokenRepositoryInterface
    {
        return self::getContainer()->get(McpAccessTokenRepositoryInterface::class);
    }
}
