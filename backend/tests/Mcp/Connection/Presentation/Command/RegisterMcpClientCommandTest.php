<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Presentation\Command;

use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\Repository\McpClientRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * `app:mcp:client:register` (#647) : la seule voie d'enregistrement d'un
 * client MCP — aucun `registration_endpoint`, aucun enregistrement dynamique.
 * Base `_test` réelle : le client est relu par le dépôt.
 */
final class RegisterMcpClientCommandTest extends KernelTestCase
{
    /** Les tables du modèle, des feuilles vers la racine. */
    private const array TABLES = ['mcp_refresh_token', 'mcp_access_token', 'mcp_authorization_code', 'mcp_connection', 'mcp_client'];

    private const string CLIENT_ID = 'claude-code-dev';

    protected function setUp(): void
    {
        self::bootKernel();
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    public function testRegistersAClientWithItsRedirects(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute([
            '--client-id' => self::CLIENT_ID,
            '--name' => 'Claude Code',
            '--redirect-uri' => ['http://localhost/callback', 'https://app.example/cb'],
        ], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        $display = $tester->getDisplay();
        self::assertStringContainsString(self::CLIENT_ID, $display);
        self::assertStringContainsString('http://localhost/callback', $display);
        self::assertStringContainsString('https://app.example/cb', $display);

        $this->entityManager()->clear();
        $client = $this->clients()->findOneByClientId(self::CLIENT_ID);
        self::assertInstanceOf(McpClient::class, $client);
        self::assertSame('Claude Code', $client->getName());
        self::assertSame(['http://localhost/callback', 'https://app.example/cb'], $client->redirectUris());
        self::assertTrue($client->allowsRedirect('http://localhost:53412/callback'));
    }

    /**
     * Le refus ne cite pas l'identifiant : les messages de la commande ne
     * reprennent aucune valeur saisie, comme ceux de ses exceptions.
     */
    public function testAClientIdAlreadyTakenIsRefusedWithoutQuotingIt(): void
    {
        $this->commandTester()->execute([
            '--client-id' => self::CLIENT_ID,
            '--name' => 'Premier',
            '--redirect-uri' => ['http://localhost/callback'],
        ], ['interactive' => false]);

        $tester = $this->commandTester();
        $exitCode = $tester->execute([
            '--client-id' => self::CLIENT_ID,
            '--name' => 'Second',
            '--redirect-uri' => ['http://127.0.0.1/callback'],
        ], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('déjà', $tester->getDisplay());
        self::assertStringNotContainsString(self::CLIENT_ID, $tester->getDisplay());

        $this->entityManager()->clear();
        $client = $this->clients()->findOneByClientId(self::CLIENT_ID);
        self::assertInstanceOf(McpClient::class, $client);
        self::assertSame('Premier', $client->getName());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function refusedInputs(): array
    {
        $valid = ['--client-id' => self::CLIENT_ID, '--name' => 'Claude Code', '--redirect-uri' => ['http://localhost/callback']];

        return [
            'redirection refusée' => [['--redirect-uri' => ['http://localhost/callback', 'http://app.example/cb']] + $valid, 'n°2'],
            'aucune redirection' => [['--redirect-uri' => []] + $valid, '--redirect-uri'],
            'sans --client-id' => [array_diff_key($valid, ['--client-id' => true]), '--client-id'],
            'sans --name' => [array_diff_key($valid, ['--name' => true]), '--name'],
            'client-id vide' => [['--client-id' => ''] + $valid, '--client-id'],
            'client-id hors alphabet' => [['--client-id' => 'claude code'] + $valid, '--client-id'],
            'client-id suivi d\'un saut de ligne' => [['--client-id' => self::CLIENT_ID."\n"] + $valid, '--client-id'],
            'client-id de 256 caractères' => [['--client-id' => str_repeat('a', 256)] + $valid, '--client-id'],
            'nom vide' => [['--name' => '   '] + $valid, '--name'],
            'nom de 101 caractères' => [['--name' => str_repeat('é', 101)] + $valid, '--name'],
            'nom avec un caractère de contrôle' => [['--name' => "Claude\u{0007}Code"] + $valid, '--name'],
            'nom en UTF-8 invalide' => [['--name' => "Claude \xC3"] + $valid, '--name'],
            'nom fait d\'espaces insécables' => [['--name' => "\u{00A0}\u{00A0}"] + $valid, '--name'],
            'nom fait d\'espaces idéographiques' => [['--name' => "\u{3000}\u{3000}"] + $valid, '--name'],
            'nom avec un séparateur de ligne' => [['--name' => "Claude\u{2028}Code"] + $valid, '--name'],
            'nom avec un séparateur de paragraphe' => [['--name' => "Claude\u{2029}Code"] + $valid, '--name'],
            'redirection au port 0' => [['--redirect-uri' => ['http://localhost:0/callback']] + $valid, 'n°1'],
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('refusedInputs')]
    public function testAnInvalidInputIsRefusedAndNothingIsRegistered(array $input, string $namedInMessage): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute($input, ['interactive' => false]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString($namedInMessage, $tester->getDisplay());
        self::assertSame(0, $this->clientCount());
    }

    /**
     * Le nom est rogné des blancs Unicode, pas seulement ASCII ; une espace
     * insécable à l'intérieur reste.
     */
    public function testTheNameIsTrimmedOfUnicodeBlanks(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute([
            '--client-id' => self::CLIENT_ID,
            '--name' => "\u{3000}Claude\u{00A0}Code\u{00A0} ",
            '--redirect-uri' => ['http://localhost/callback'],
        ], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        $this->entityManager()->clear();
        self::assertSame("Claude\u{00A0}Code", $this->clients()->findOneByClientId(self::CLIENT_ID)?->getName());
    }

    /** Une redirection répétée à l'identique n'est enregistrée qu'une fois. */
    public function testARepeatedRedirectIsRegisteredOnce(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute([
            '--client-id' => self::CLIENT_ID,
            '--name' => 'Claude Code',
            '--redirect-uri' => ['http://localhost/callback', 'https://app.example/cb', 'http://localhost/callback'],
        ], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        $this->entityManager()->clear();
        self::assertSame(['http://localhost/callback', 'https://app.example/cb'], $this->clients()->findOneByClientId(self::CLIENT_ID)?->redirectUris());
    }

    /** Les bornes elles-mêmes passent : 255 caractères d'identifiant, 100 de nom. */
    public function testTheBoundsThemselvesAreAccepted(): void
    {
        $clientId = 'https://app.example/'.str_repeat('a', 235);
        $tester = $this->commandTester();

        $exitCode = $tester->execute([
            '--client-id' => $clientId,
            '--name' => str_repeat('é', 100),
            '--redirect-uri' => ['https://app.example/cb'],
        ], ['interactive' => false]);

        self::assertSame(255, \strlen($clientId));
        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        self::assertSame(1, $this->clientCount());
    }

    private function commandTester(): CommandTester
    {
        \assert(self::$kernel instanceof KernelInterface);
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:mcp:client:register'));
    }

    private function clients(): McpClientRepositoryInterface
    {
        return self::getContainer()->get(McpClientRepositoryInterface::class);
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function clientCount(): int
    {
        $count = $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM mcp_client');

        return is_numeric($count) ? (int) $count : -1;
    }

    private function purge(): void
    {
        $connection = $this->entityManager()->getConnection();
        foreach (self::TABLES as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
        $this->entityManager()->clear();
    }
}
