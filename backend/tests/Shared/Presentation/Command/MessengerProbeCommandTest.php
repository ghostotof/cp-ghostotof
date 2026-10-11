<?php

declare(strict_types=1);

namespace App\Tests\Shared\Presentation\Command;

use App\Shared\Presentation\Command\MessengerProbeCommand;
use App\Tests\Shared\Presentation\Command\Fixtures\RecordingProbe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Exception\TransportException;

/**
 * `app:messenger:probe` (issue #470) : son code de sortie est ce que lit le
 * Job du smoke préprod, donc `rollback-preprod`.
 */
final class MessengerProbeCommandTest extends TestCase
{
    public function testItSucceedsWhenTheWorkerHandledTheProbe(): void
    {
        $tester = $this->tester(true);

        self::assertSame(Command::SUCCESS, $tester->execute(['--timeout' => '30']));
        self::assertStringContainsString('consommé', $tester->getDisplay());
    }

    /**
     * L'échec nomme les causes à vérifier : c'est tout ce que l'opérateur lit
     * dans `kubectl logs` du Job.
     */
    public function testItFailsWhenTheWorkerNeverHandledTheProbe(): void
    {
        $tester = $this->tester(false);

        self::assertSame(Command::FAILURE, $tester->execute(['--timeout' => '30']));
        self::assertStringContainsString('failed', $tester->getDisplay());
        self::assertStringContainsString('worker', $tester->getDisplay());
    }

    /**
     * Depuis #479, une publication refusée ou non confirmée par le broker lève
     * une exception de transport : la sonde dit laquelle et échoue, au lieu
     * d'une trace d'exception non rattrapée dans les journaux du Job.
     */
    public function testAPublicationTheBrokerRefusesFailsWithItsCause(): void
    {
        $tester = new CommandTester(new MessengerProbeCommand(new RecordingProbe(true, new TransportException('Message publication failed: PRECONDITION_FAILED.'))));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('publication', $tester->getDisplay());
        self::assertStringContainsString('PRECONDITION_FAILED', $tester->getDisplay());
    }

    public function testItWaitsSixtySecondsByDefault(): void
    {
        $probe = new RecordingProbe(true);
        new CommandTester(new MessengerProbeCommand($probe))->execute([]);

        self::assertSame([60], $probe->timeouts);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTimeouts(): iterable
    {
        yield 'zéro' => ['0'];
        yield 'négatif' => ['-5'];
        yield 'au-delà de dix minutes' => ['601'];
        yield 'pas un nombre' => ['soixante'];
        yield 'décimal' => ['1.5'];
    }

    #[DataProvider('invalidTimeouts')]
    public function testAnInvalidTimeoutIsRefusedBeforeAnyMessageIsQueued(string $timeout): void
    {
        $probe = new RecordingProbe(true);
        $tester = new CommandTester(new MessengerProbeCommand($probe));

        self::assertSame(Command::INVALID, $tester->execute(['--timeout' => $timeout]));
        self::assertSame([], $probe->timeouts);
    }

    private function tester(bool $handled): CommandTester
    {
        return new CommandTester(new MessengerProbeCommand(new RecordingProbe($handled)));
    }
}
