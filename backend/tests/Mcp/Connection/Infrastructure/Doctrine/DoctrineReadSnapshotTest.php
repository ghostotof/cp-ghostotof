<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Infrastructure\Doctrine;

use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use App\Mcp\Connection\Infrastructure\Doctrine\DoctrineReadSnapshot;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Mcp\Connection\RegisteredMcpClient;
use App\Tests\Support\OpensProbeConnection;
use DateTimeImmutable;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * L'instantané commun de « Mes connexions MCP » (#650, CR3 de la seconde
 * relecture) sur la vraie base `_test` : deux lectures d'une même fermeture
 * voient le même instant, même si une autre session valide entre elles.
 *
 * L'entrelacement est écrit une fois, sans processus fils ni verrou : la
 * fermeture lit une moitié, **une seconde connexion** révoque et valide, la
 * fermeture lit l'autre — exactement la course d'une révocation arrivée entre
 * les deux requêtes de la liste.
 */
final class DoctrineReadSnapshotTest extends KernelTestCase
{
    use OpensProbeConnection;

    private const string SCOPE = 'nominative:read';

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
     * Le cas que la liste craignait : la moitié terminée est lue (vide), une
     * autre session révoque la connexion, la moitié non révoquée est lue. En
     * lecture validée la connexion disparaîtrait des deux ; dans l'instantané
     * elle est encore active pour la seconde lecture, comme pour la première.
     */
    public function testARevocationCommittedBetweenTwoReadsIsInvisibleToTheSecondOne(): void
    {
        $connection = $this->grantedConnection();
        $repository = $this->repository();

        [$terminated, $notRevoked] = $this->snapshot()->read(function () use ($repository, $connection): array {
            $terminated = $repository->findTerminatedOfAccount($connection->account()->getId(), 10);
            $this->revokeFromAnotherSession($connection);

            return [$terminated, $repository->findNotRevokedOfAccount($connection->account()->getId())];
        });

        self::assertSame([], $terminated);
        self::assertSame([$connection->getId()->toRfc4122()], array_map(static fn (McpConnection $c): string => $c->getId()->toRfc4122(), $notRevoked), 'La connexion est encore active pour tout l\'instantané.');
    }

    /** L'instantané se referme : une lecture qui le suit voit, elle, la révocation validée. */
    public function testTheSnapshotEndsWhenTheReadsReturn(): void
    {
        $connection = $this->grantedConnection();
        $repository = $this->repository();

        $this->snapshot()->read(function () use ($repository, $connection): void {
            $repository->findTerminatedOfAccount($connection->account()->getId(), 10);
            $this->revokeFromAnotherSession($connection);
        });

        self::assertFalse($this->em->getConnection()->isTransactionActive());
        self::assertSame([], $repository->findNotRevokedOfAccount($connection->account()->getId()));
        self::assertCount(1, $repository->findTerminatedOfAccount($connection->account()->getId(), 10));
    }

    /** Un instantané ne fait que lire : une écriture y est refusée par la base, pas par la politesse de l'appelant. */
    public function testAWriteInsideTheSnapshotIsRefusedByTheDatabase(): void
    {
        $connection = $this->grantedConnection();
        $database = $this->em->getConnection();

        try {
            $this->snapshot()->read(static function () use ($database, $connection): void {
                $database->executeStatement('UPDATE mcp_connection SET scope = scope WHERE id = :id', ['id' => $connection->getId()], ['id' => 'uuid']);
            });
            self::fail('L\'écriture aurait dû être refusée.');
        } catch (DbalException $refusal) {
            self::assertStringContainsString('read-only transaction', $refusal->getMessage());
        }

        self::assertFalse($database->isTransactionActive(), 'L\'instantané est annulé, la connexion rendue propre.');
    }

    /** Une fermeture qui lève : l'exception est relancée telle quelle, la transaction annulée. */
    public function testAnExceptionOfTheReadsIsRethrownAndTheSnapshotClosed(): void
    {
        $failure = new RuntimeException('lecture impossible');
        $raised = null;

        try {
            $this->snapshot()->read(static function () use ($failure): void {
                throw $failure;
            });
        } catch (RuntimeException $caught) {
            $raised = $caught;
        }

        self::assertSame($failure, $raised, 'L\'exception remonte telle quelle.');
        self::assertFalse($this->em->getConnection()->isTransactionActive());
    }

    /**
     * Le niveau d'isolation ne se règle qu'avant la première requête d'une
     * transaction : dans une transaction déjà ouverte, un « instantané » serait
     * un savepoint qui ne protège de rien. L'appelant est refusé avant tout, sans
     * rien ouvrir (comme les verrous de l'application, #526).
     */
    public function testAnOpenTransactionIsRefusedRatherThanNestedAsAFalseSnapshot(): void
    {
        $database = $this->em->getConnection();
        $database->beginTransaction();
        $ran = false;

        try {
            $this->snapshot()->read(static function () use (&$ran): void {
                $ran = true;
            });
            self::fail('Un instantané dans une transaction aurait dû être refusé.');
        } catch (LogicException) {
            self::assertFalse($ran, 'Aucune lecture n\'a eu lieu.');
            self::assertSame(1, $database->getTransactionNestingLevel(), 'Rien n\'a été ouvert de plus.');
        } finally {
            $database->rollBack();
        }
    }

    private function revokeFromAnotherSession(McpConnection $connection): void
    {
        $other = self::openProbeBeside($this->em->getConnection());
        try {
            $other->executeStatement(
                "UPDATE mcp_connection SET revoked_at = now(), revocation_cause = :cause WHERE id = :id",
                ['cause' => RevocationCause::Person->value, 'id' => $connection->getId()],
                ['id' => 'uuid'],
            );
        } finally {
            $other->close();
        }
    }

    private function grantedConnection(): McpConnection
    {
        $account = new CpgUser('jane', 'hashed-password');
        $this->em->persist($account);
        $client = RegisteredMcpClient::of('https://client.example.test/metadata.json', 'Client de test', ['https://client.example.test/callback']);
        $this->em->persist($client);
        $connection = McpConnection::grant($account, $client, self::SCOPE, new DateTimeImmutable('2026-10-20T10:00:00+02:00'));
        $this->em->persist($connection);
        $this->em->flush();
        $this->em->clear();

        return $connection;
    }

    private function snapshot(): DoctrineReadSnapshot
    {
        return new DoctrineReadSnapshot($this->em);
    }

    private function repository(): McpConnectionRepositoryInterface
    {
        return self::getContainer()->get(McpConnectionRepositoryInterface::class);
    }

    private function purge(): void
    {
        $database = $this->em->getConnection();
        foreach (['mcp_refresh_token', 'mcp_access_token', 'mcp_authorization_code', 'mcp_connection', 'mcp_client'] as $table) {
            $database->executeStatement('DELETE FROM '.$table);
        }
        $database->executeStatement('DELETE FROM cpg_user');
        $this->em->clear();
    }
}
