<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Infrastructure\Doctrine;

use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\CimdClientId;
use App\Mcp\Connection\Domain\ValueObject\ClientMetadataDocument;
use App\Mcp\Connection\Domain\ValueObject\ClientRegistration;
use App\Mcp\Connection\Domain\ValueObject\McpClientId;
use App\Tests\Mcp\Connection\Fixtures\ClaudeCodeMetadata;
use App\Tests\Mcp\Connection\RegisteredMcpClient;
use App\Tests\Support\TestDatabase;
use DateTimeImmutable;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration Doctrine (base `_test`) du mode d'enregistrement d'un
 * client MCP (#651) : la colonne `registration`, sa contrainte, le dépôt d'un
 * client déclaré par son document (`saveDiscovered`, un INSERT … ON CONFLICT
 * qui ne réécrit jamais une ligne pré-enregistrée) et la question
 * `isDiscovered` qui choisit la voie du quota de récupération.
 */
final class McpClientRepositoryTest extends KernelTestCase
{
    private const string PREREGISTERED_ID = 'https://client.example.test/metadata.json';

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

    public function testOnlyACimdRowIsDiscovered(): void
    {
        $this->repository()->add(RegisteredMcpClient::of(self::PREREGISTERED_ID, 'Pré-enregistré', ['https://client.example.test/cb']));
        self::assertInstanceOf(McpClient::class, $this->repository()->saveDiscovered($this->discovered()));

        self::assertTrue($this->repository()->isDiscovered(McpClientId::fromString(ClaudeCodeMetadata::CLIENT_ID)));
        self::assertFalse($this->repository()->isDiscovered(McpClientId::fromString(self::PREREGISTERED_ID)));
        self::assertFalse($this->repository()->isDiscovered(McpClientId::fromString('https://absent.example.test/metadata.json')));
    }

    public function testADiscoveredClientIsInsertedOnce(): void
    {
        $saved = $this->repository()->saveDiscovered($this->discovered());

        self::assertInstanceOf(McpClient::class, $saved);
        self::assertSame(ClientRegistration::Cimd, $saved->registration());
        self::assertSame('Claude Code', $saved->getName());
        self::assertSame(1, $this->countRows());
    }

    public function testASecondSaveRefreshesNameAndRedirectsAndKeepsIdAndDate(): void
    {
        $first = $this->repository()->saveDiscovered($this->discovered(registeredAt: new DateTimeImmutable('2026-11-01T10:00:00+01:00')));
        self::assertInstanceOf(McpClient::class, $first);
        $id = $first->getId()->toRfc4122();
        $registeredAt = $first->registeredAt();

        $second = $this->repository()->saveDiscovered($this->discovered(
            json: '{"client_id":"'.ClaudeCodeMetadata::CLIENT_ID.'","client_name":"Claude Code 2","redirect_uris":["http://localhost/other"],"token_endpoint_auth_method":"none"}',
            registeredAt: new DateTimeImmutable('2026-11-13T10:00:00+01:00'),
        ));

        self::assertInstanceOf(McpClient::class, $second);
        self::assertSame($id, $second->getId()->toRfc4122(), 'Le client garde son identifiant : les connexions y sont attachées.');
        self::assertSame('Claude Code 2', $second->getName());
        self::assertSame(['http://localhost/other'], $second->redirectUris());
        self::assertSame($registeredAt->getTimestamp(), $second->registeredAt()->getTimestamp(), 'La date d\'enregistrement est celle du premier dépôt.');
        self::assertSame(1, $this->countRows());
    }

    /** Un client pré-enregistré (le sien est jugé par une personne) n'est jamais réécrit par un document. */
    public function testAPreregisteredRowIsNeverOverwritten(): void
    {
        $this->repository()->add(RegisteredMcpClient::of(ClaudeCodeMetadata::CLIENT_ID, 'Nom choisi par une personne', ['https://client.example.test/cb']));
        $this->em->clear();

        self::assertNull($this->repository()->saveDiscovered($this->discovered()));

        $row = $this->em->getConnection()->fetchAssociative('SELECT name, redirect_uris, registration FROM mcp_client');
        self::assertIsArray($row);
        self::assertSame('Nom choisi par une personne', $row['name']);
        self::assertSame('["https:\/\/client.example.test\/cb"]', $row['redirect_uris']);
        self::assertSame('preregistered', $row['registration']);
    }

    public function testTheColumnRefusesAnUnknownRegistration(): void
    {
        $this->repository()->add(RegisteredMcpClient::of(self::PREREGISTERED_ID, 'Client', ['https://client.example.test/cb']));

        $this->expectException(DbalException::class);
        $this->em->getConnection()->executeStatement("UPDATE mcp_client SET registration = 'x'");
    }

    /** Une ligne écrite sans la colonne (une version de l'application d'avant) est pré-enregistrée. */
    public function testARowWrittenWithoutTheColumnIsPreregistered(): void
    {
        $this->em->getConnection()->executeStatement(
            "INSERT INTO mcp_client (id, client_id, name, redirect_uris, registered_at) VALUES ('0195f3a0-0000-7000-8000-000000000001', :clientId, 'Ancien', '[]', NOW())",
            ['clientId' => self::PREREGISTERED_ID],
        );

        self::assertSame('preregistered', $this->em->getConnection()->fetchOne('SELECT registration FROM mcp_client'));
        self::assertFalse($this->repository()->isDiscovered(McpClientId::fromString(self::PREREGISTERED_ID)));
    }

    private function discovered(?string $json = null, ?DateTimeImmutable $registeredAt = null): McpClient
    {
        $clientId = CimdClientId::tryFrom(ClaudeCodeMetadata::CLIENT_ID);
        self::assertNotNull($clientId);

        return McpClient::discovered(
            ClientMetadataDocument::fromJson($clientId, $json ?? ClaudeCodeMetadata::json()),
            $registeredAt ?? new DateTimeImmutable('2026-11-13T10:00:00+01:00'),
        );
    }

    private function countRows(): int
    {
        $count = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM mcp_client');
        self::assertIsInt($count);

        return $count;
    }

    private function purge(): void
    {
        $connection = $this->em->getConnection();
        TestDatabase::assertSafeToWipe($connection);
        foreach (['mcp_refresh_token', 'mcp_access_token', 'mcp_authorization_code', 'mcp_connection', 'mcp_client'] as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
        $this->em->clear();
    }

    private function repository(): McpClientRepositoryInterface
    {
        return self::getContainer()->get(McpClientRepositoryInterface::class);
    }
}
