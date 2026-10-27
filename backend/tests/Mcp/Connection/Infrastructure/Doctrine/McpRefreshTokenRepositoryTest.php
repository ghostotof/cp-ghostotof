<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Infrastructure\Doctrine;

use App\Mcp\Connection\Domain\Entity\McpAccessToken;
use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\Entity\McpRefreshToken;
use App\Mcp\Connection\Domain\Repository\McpAccessTokenRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpRefreshTokenRepositoryInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Mcp\Connection\RegisteredMcpClient;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration Doctrine (base `_test`) de la lecture d'un jeton de
 * rafraîchissement par son empreinte (#649, lot C des relectures).
 *
 * Le point de révocation lit l'empreinte du jeton d'accès du rafraîchissement
 * **avant** d'ouvrir sa transaction (la lecture, puis C → A → R dedans). Un
 * jeton d'accès laissé en proxy par la lecture s'initialisait à cette
 * lecture-là, et la purge quotidienne pouvant supprimer sa ligne entre les
 * deux, le proxy levait `EntityNotFoundException` — un 500 — là où le lien,
 * remis à NULL par la cascade `ON DELETE SET NULL`, se lit `null`. Chargé
 * avec le rafraîchissement, il reste lisible quoi qu'il advienne de sa ligne
 * ensuite.
 */
final class McpRefreshTokenRepositoryTest extends KernelTestCase
{
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
     * Mesuré sans la jointure du jeton d'accès dans `findOneByHash()` : le
     * jeton d'accès est un objet paresseux (un proxy, natif depuis PHP 8.4 —
     * aucune interface ne le distingue, seul son comportement compte), et sa
     * lecture après la suppression de la ligne lève `EntityNotFoundException`.
     */
    public function testTheAccessTokenIsLoadedWithTheRefreshTokenAndStaysReadableOnceItsRowIsGone(): void
    {
        $account = new CpgUser('jane', 'hashed-password');
        $this->em->persist($account);
        $this->em->flush();
        $client = RegisteredMcpClient::of(self::CLIENT_ID, 'Client de test', ['https://client.example.test/callback']);
        self::getContainer()->get(McpClientRepositoryInterface::class)->add($client);
        $connection = McpConnection::grant($account, $client, McpConnection::SCOPE_NOMINATIVE_READ, new DateTimeImmutable());
        self::getContainer()->get(McpConnectionRepositoryInterface::class)->add($connection);
        $accessHash = hash('sha256', 'clear-access-token');
        $accessToken = new McpAccessToken($connection, $accessHash, self::AUDIENCE, new DateTimeImmutable('+1 hour'));
        self::getContainer()->get(McpAccessTokenRepositoryInterface::class)->add($accessToken);
        $refreshHash = hash('sha256', 'clear-refresh-token');
        $this->refreshTokens()->add(new McpRefreshToken($connection, $refreshHash, $accessToken, new DateTimeImmutable('+30 days')));
        $this->em->clear();

        $refresh = $this->refreshTokens()->findOneByHash($refreshHash);
        self::assertInstanceOf(McpRefreshToken::class, $refresh);

        // La purge passe entre la lecture et la transaction : la ligne du
        // jeton d'accès disparaît, le lien est remis à NULL par la cascade.
        // Rien n'a encore lu le jeton d'accès : c'est cette lecture-ci que le
        // point de révocation fait avant d'ouvrir sa transaction.
        $this->em->getConnection()->executeStatement('DELETE FROM mcp_access_token WHERE id = :id', ['id' => $accessToken->getId()], ['id' => UuidType::NAME]);

        self::assertSame($accessHash, $refresh->accessToken()?->tokenHash());
    }

    private function refreshTokens(): McpRefreshTokenRepositoryInterface
    {
        return self::getContainer()->get(McpRefreshTokenRepositoryInterface::class);
    }

    private function purge(): void
    {
        $connection = $this->em->getConnection();
        foreach (self::TABLES as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
        $connection->executeStatement('DELETE FROM cpg_user');
        $this->em->clear();
    }
}
