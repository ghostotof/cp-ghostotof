<?php

declare(strict_types=1);

namespace App\Tests\Contact\Presentation\Command;

use App\Contact\Application\Message\SendContactMessageMessage;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Couvre app:contact:purge-failed-messages : seuls les messages en échec
 * antérieurs à la fenêtre de rétention sont supprimés, les récents sont
 * conservés, et un intervalle invalide échoue proprement.
 */
final class PurgeFailedContactMessagesCommandTest extends KernelTestCase
{
    private Connection $connection;
    private TransportInterface $failedTransport;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->failedTransport = self::getContainer()->get('messenger.transport.failed');
        $this->truncateMessengerTable();
    }

    protected function tearDown(): void
    {
        $this->truncateMessengerTable();
        parent::tearDown();
    }

    public function testPurgesOnlyMessagesOlderThanRetentionWindow(): void
    {
        // Deux messages en échec : la table messenger_messages est créée au
        // premier send() par le transport Doctrine (auto_setup).
        $this->failedTransport->send(new Envelope($this->message('old@example.com')));
        $this->failedTransport->send(new Envelope($this->message('recent@example.com')));

        // On vieillit artificiellement le premier au-delà de la fenêtre.
        $this->connection->executeStatement(
            "UPDATE messenger_messages SET created_at = :old WHERE body LIKE '%old@example.com%'",
            ['old' => (new \DateTimeImmutable('-40 days'))->format('Y-m-d H:i:s')],
        );

        $exitCode = $this->commandTester()->execute(['--older-than' => '30 days']);

        self::assertSame(0, $exitCode);
        self::assertSame(0, $this->countByRecipient('old@example.com'));
        self::assertSame(1, $this->countByRecipient('recent@example.com'));
    }

    public function testFailsOnInvalidInterval(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute(['--older-than' => 'not-an-interval']);

        self::assertSame(2, $exitCode); // Command::INVALID
        self::assertStringContainsString('illisible', $tester->getDisplay());
    }

    /**
     * Issue #248 : `new \DateTimeImmutable('-'.$olderThan)` acceptait la
     * double négation "--30 days" (interprétée "+30 days") et purgeait tout,
     * y compris un message en échec récent. `RetentionPeriod` doit refuser
     * cette expression avant que la moindre suppression ait lieu.
     */
    public function testRejectsANegativeOlderThanInsteadOfPurgingEverything(): void
    {
        $this->failedTransport->send(new Envelope($this->message('recent@example.com')));
        $tester = $this->commandTester();

        $exitCode = $tester->execute(['--older-than' => '-30 days']);

        self::assertSame(2, $exitCode); // Command::INVALID
        self::assertStringContainsString('strictement positive', $tester->getDisplay());
        self::assertSame(1, $this->countByRecipient('recent@example.com'));
    }

    /**
     * Issue #248, même défaut sous une autre forme : une durée nulle place le
     * seuil exactement à `now`, ce qui purge tout aussi.
     */
    public function testRejectsAZeroOlderThan(): void
    {
        $this->failedTransport->send(new Envelope($this->message('recent@example.com')));

        $exitCode = $this->commandTester()->execute(['--older-than' => '0 days']);

        self::assertSame(2, $exitCode); // Command::INVALID
        self::assertSame(1, $this->countByRecipient('recent@example.com'));
    }

    private function message(string $senderEmail): SendContactMessageMessage
    {
        return new SendContactMessageMessage(
            senderName: 'Jane Doe',
            senderEmail: $senderEmail,
            body: 'Message en echec de test.',
        );
    }

    private function countByRecipient(string $senderEmail): int
    {
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = :queue AND body LIKE :needle',
            ['queue' => 'failed', 'needle' => '%'.$senderEmail.'%'],
        );
        \assert(is_numeric($count));

        return (int) $count;
    }

    private function truncateMessengerTable(): void
    {
        try {
            $this->connection->executeStatement('DELETE FROM messenger_messages');
        } catch (TableNotFoundException) {
            // Table pas encore créée : rien à nettoyer.
        }
    }

    private function commandTester(): CommandTester
    {
        \assert(self::$kernel instanceof KernelInterface);
        return new CommandTester((new Application(self::$kernel))->find('app:contact:purge-failed-messages'));
    }
}
