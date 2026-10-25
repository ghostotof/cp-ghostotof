<?php

declare(strict_types=1);

namespace App\Tests\Contact\Presentation\Command;

use App\Contact\Application\Message\SendContactMessageMessage;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
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
    use ClockSensitiveTrait;

    /** `date.timezone` des images (php.*.ini) : le fuseau où la commande compte ses jours. */
    private const string PHP_TIMEZONE = 'Europe/Paris';

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
        $nowUtc = $this->freezeClockAt('2026-10-15 12:00:00');

        // Deux messages en échec : la table messenger_messages est créée au
        // premier send() par le transport Doctrine (auto_setup).
        $this->failedTransport->send(new Envelope($this->message('old@example.com')));
        $this->failedTransport->send(new Envelope($this->message('recent@example.com')));

        // Le premier au-delà de la fenêtre, le second en deçà, dans l'heure
        // murale UTC du transport.
        $this->setCreatedAt('old@example.com', $nowUtc->modify('-40 days'));
        $this->setCreatedAt('recent@example.com', $nowUtc->modify('-1 day'));

        $exitCode = $this->commandTester()->execute(['--older-than' => '30 days']);

        self::assertSame(0, $exitCode);
        self::assertSame(0, $this->countByRecipient('old@example.com'));
        self::assertSame(1, $this->countByRecipient('recent@example.com'));
    }

    /**
     * Issue #446 : le transport Doctrine de Messenger écrit `created_at` en
     * heure murale UTC (`new DateTimeImmutable('UTC')`, colonne sans fuseau).
     * Le seuil doit l'être aussi : formaté en heure de Paris, en avance d'une
     * ou deux heures, il purgeait un message encore dans sa fenêtre.
     *
     * Issue #709 : « 30 days » se compte en jours du calendrier de Paris
     * (règle de #445), puis se convertit en UTC. Quand la fenêtre contient un
     * changement d'heure, ce seuil s'écarte d'une heure de « maintenant UTC
     * moins 30 jours » ; le test qui calculait ainsi sa référence rougissait
     * pendant les 30 jours qui suivent chaque changement d'heure. Les seuils
     * attendus sont donc écrits en clair, pour une horloge figée de part et
     * d'autre des deux changements d'heure.
     *
     * L'instant figé l'est dans le fuseau de PHP, comme le donne l'horloge
     * native en production : figé en UTC, la commande compterait des jours UTC
     * et le test ne verrait plus la règle des jours de calendrier.
     */
    #[DataProvider('retentionThresholds')]
    public function testTheRetentionWindowCountsCalendarDaysThenComparesInTheTransportsUtcClock(string $nowUtc, string $expectedThresholdUtc): void
    {
        self::assertSame(self::PHP_TIMEZONE, date_default_timezone_get(), 'Les seuils attendus sont écrits pour le fuseau de PHP du projet, celui de l\'horloge de production.');
        $this->freezeClockAt($nowUtc);
        $threshold = new DateTimeImmutable($expectedThresholdUtc, new DateTimeZone('UTC'));

        $this->failedTransport->send(new Envelope($this->message('inside@example.com')));
        $this->failedTransport->send(new Envelope($this->message('outside@example.com')));
        $this->setCreatedAt('inside@example.com', $threshold->modify('+30 minutes'));
        $this->setCreatedAt('outside@example.com', $threshold->modify('-30 minutes'));

        $tester = $this->commandTester();
        $exitCode = $tester->execute(['--older-than' => '30 days']);

        self::assertSame(0, $exitCode);
        // SymfonyStyle replie la ligne à la largeur du terminal : le seuil
        // peut y être coupé entre la date et l'heure.
        self::assertStringContainsString($expectedThresholdUtc.' UTC', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
        self::assertSame(1, $this->countByRecipient('inside@example.com'), 'Un message encore dans sa fenêtre de rétention a été purgé.');
        self::assertSame(0, $this->countByRecipient('outside@example.com'), 'Un message sorti de sa fenêtre de rétention a été conservé.');
    }

    /**
     * Instant figé, puis seuil attendu : 30 jours du calendrier de Paris avant
     * cet instant, en heure murale UTC.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function retentionThresholds(): iterable
    {
        // 2026-10-25 04:28 CET − 30 jours = 2026-09-25 04:28 CEST : l'instant mesuré de #709.
        yield 'heure d\'hiver entrée dans la fenêtre' => ['2026-10-25 03:28:00', '2026-09-25 02:28:00'];
        // 2027-04-10 14:00 CEST − 30 jours = 2027-03-11 14:00 CET (passage du 2027-03-28).
        yield 'heure d\'été entrée dans la fenêtre' => ['2027-04-10 12:00:00', '2027-03-11 13:00:00'];
        // Ni l'un ni l'autre : le seuil reste à 30 × 24 heures, en été puis en hiver.
        yield 'été sans changement d\'heure' => ['2026-10-15 12:00:00', '2026-09-15 12:00:00'];
        yield 'hiver sans changement d\'heure' => ['2027-01-15 12:00:00', '2026-12-16 12:00:00'];
    }

    /**
     * Fige l'horloge à cet instant UTC, exprimé dans le fuseau de PHP comme le
     * rend l'horloge native, et le renvoie en UTC.
     */
    private function freezeClockAt(string $nowUtc): DateTimeImmutable
    {
        $now = new DateTimeImmutable($nowUtc, new DateTimeZone('UTC'));
        self::mockTime($now->setTimezone(new DateTimeZone(self::PHP_TIMEZONE)));

        return $now;
    }

    /**
     * Comme l'écrit le transport : heure murale UTC, sans décalage.
     */
    private function setCreatedAt(string $senderEmail, DateTimeImmutable $createdAtUtc): void
    {
        $this->connection->executeStatement(
            'UPDATE messenger_messages SET created_at = :createdAt WHERE body LIKE :needle',
            ['createdAt' => $createdAtUtc->format('Y-m-d H:i:s'), 'needle' => '%'.$senderEmail.'%'],
        );
    }

    public function testFailsOnInvalidInterval(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute(['--older-than' => 'not-an-interval']);

        self::assertSame(2, $exitCode); // Command::INVALID
        self::assertStringContainsString('illisible', $tester->getDisplay());
    }

    /**
     * Issue #248 : `new DateTimeImmutable('-'.$olderThan)` acceptait la
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
