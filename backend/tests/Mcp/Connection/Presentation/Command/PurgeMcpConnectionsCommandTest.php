<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Presentation\Command;

use App\Mcp\Connection\Domain\Entity\McpAccessToken;
use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\Entity\McpRefreshToken;
use App\Mcp\Connection\Domain\Repository\McpAccessTokenRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\Repository\McpRefreshTokenRepositoryInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Mcp\Connection\RegisteredMcpClient;
use App\Tests\Support\TestDatabase;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * `app:mcp:purge` (#649), l'habillage CLI de McpConnectionPurger lancé chaque
 * nuit par le CronJob de ménage : la rétention par défaut vient de la
 * configuration (six mois), une durée illisible ou trop courte est refusée en
 * `Command::INVALID`, et la sortie — le journal du pod — ne désigne une
 * connexion que par son UUID.
 */
final class PurgeMcpConnectionsCommandTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private const array TABLES = ['mcp_refresh_token', 'mcp_access_token', 'mcp_authorization_code', 'mcp_connection', 'mcp_client'];

    private const string CLIENT_ID = 'https://client.example.test/metadata.json';
    private const string USERNAME = 'purge-command-account';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        // Le 10 novembre 2026 à 10:00 à Paris : six mois plus tôt, le 10 mai à 10:00 CEST.
        self::mockTime(new DateTimeImmutable('2026-11-10 10:00:00', new DateTimeZone('Europe/Paris')));
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->purgeTables();
    }

    protected function tearDown(): void
    {
        $this->purgeTables();
        parent::tearDown();
    }

    /** Sans option, la rétention est celle de `app.mcp.revoked_connection_retention` : six mois. */
    public function testTheDefaultRetentionIsSixMonths(): void
    {
        $tester = $this->commandTester();

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        $display = $this->flattened($tester);
        self::assertStringContainsString('2026-05-10 10:00:00 +02:00', $display, 'Le seuil affiché est celui de six mois.');
        self::assertStringContainsString('6 months', $display);
        self::assertStringContainsString('Aucune connexion expirée', $display, 'Succès même à zéro.');
    }

    /** Une expression qui désigne une date n'est pas une durée : refusée, rien n'est purgé. */
    public function testAnUnreadableRetentionIsInvalid(): void
    {
        $connection = $this->expiredConnectionWithTokens();
        $tester = $this->commandTester();

        self::assertSame(Command::INVALID, $tester->execute(['--older-than' => 'first day of next month']));
        self::assertStringContainsString('pas une durée', $this->flattened($tester));
        self::assertFalse($this->isRevoked($connection));
    }

    /** Sous un jour, le purgeur refuse : `Command::INVALID`, rien n'est purgé. */
    public function testARetentionUnderADayIsInvalid(): void
    {
        $connection = $this->expiredConnectionWithTokens();
        $tester = $this->commandTester();

        self::assertSame(Command::INVALID, $tester->execute(['--older-than' => '12 hours']));
        self::assertStringContainsString('au moins un jour', $this->flattened($tester));
        self::assertFalse($this->isRevoked($connection));
    }

    /**
     * La sortie est le journal du pod du CronJob : une connexion y est
     * désignée par son UUID, jamais par le compte, l'identifiant public du
     * client ni une empreinte de jeton (#363). Les compteurs y figurent.
     */
    public function testTheOutputNamesConnectionsByUuidOnly(): void
    {
        $connection = $this->expiredConnectionWithTokens();
        $tester = $this->commandTester();

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        $display = $this->flattened($tester);

        self::assertStringContainsString($connection->getId()->toRfc4122(), $display);
        self::assertTrue($this->isRevoked($connection));
        self::assertStringNotContainsString(self::CLIENT_ID, $display);
        self::assertStringNotContainsString(self::USERNAME, $display);
        self::assertStringNotContainsString($connection->account()->getId()->toRfc4122(), $display);
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{64}/', $display, 'Aucune empreinte de jeton.');
        self::assertStringContainsString('Jetons d\'accès supprimés : 1', $display);
        self::assertStringContainsString('Jetons de rafraîchissement supprimés : 1', $display);
    }

    /** Inactive depuis le 1er octobre : expirée le 31, avec un jeton de chaque sorte. */
    private function expiredConnectionWithTokens(): McpConnection
    {
        $account = new CpgUser(self::USERNAME, 'hashed-password');
        $this->em->persist($account);
        $this->em->flush();
        $client = RegisteredMcpClient::of(self::CLIENT_ID, 'Client de test', ['https://client.example.test/callback']);
        self::getContainer()->get(McpClientRepositoryInterface::class)->add($client);
        $connection = McpConnection::grant($account, $client, 'nominative:read', new DateTimeImmutable('2026-10-01T10:00:00+02:00'));
        self::getContainer()->get(McpConnectionRepositoryInterface::class)->add($connection);

        $expiresAt = new DateTimeImmutable('2026-11-30T10:00:00+01:00');
        $access = new McpAccessToken($connection, hash('sha256', 'purge-command-access'), 'https://example.test/api/mcp', $expiresAt);
        self::getContainer()->get(McpAccessTokenRepositoryInterface::class)->add($access);
        self::getContainer()->get(McpRefreshTokenRepositoryInterface::class)->add(
            new McpRefreshToken($connection, hash('sha256', 'purge-command-refresh'), $access, $expiresAt),
        );

        return $connection;
    }

    private function isRevoked(McpConnection $connection): bool
    {
        $this->em->clear();

        return true === self::getContainer()->get(McpConnectionRepositoryInterface::class)->findOneById($connection->getId())?->isRevoked();
    }

    /** La sortie sur une ligne : SymfonyStyle coupe les longues lignes. */
    private function flattened(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    private function commandTester(): CommandTester
    {
        \assert(self::$kernel instanceof KernelInterface);

        return new CommandTester(new Application(self::$kernel)->find('app:mcp:purge'));
    }

    private function purgeTables(): void
    {
        $connection = $this->em->getConnection();
        TestDatabase::assertSafeToWipe($connection);
        foreach (self::TABLES as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
        $connection->executeStatement('DELETE FROM cpg_user');
        $this->em->clear();
    }
}
