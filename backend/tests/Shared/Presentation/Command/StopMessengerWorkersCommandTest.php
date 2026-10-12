<?php

declare(strict_types=1);

namespace App\Tests\Shared\Presentation\Command;

use App\Shared\Application\WorkerRestartSignalNotSavedException;
use App\Shared\Presentation\Command\StopMessengerWorkersCommand;
use App\Tests\Shared\Presentation\Command\Fixtures\RecordingRestartSignal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:messenger:stop-workers` (issue #528) : son code de sortie est ce que lit
 * le Job k8s/base/messenger-stop-workers-job.yaml, étape 4 de la rotation
 * d'`APP_SECRET`. Celle de Symfony sort en 0 même quand rien n'est écrit.
 */
final class StopMessengerWorkersCommandTest extends TestCase
{
    public function testItSucceedsOnceTheSignalIsReadBack(): void
    {
        $signal = new RecordingRestartSignal();
        $tester = new CommandTester(new StopMessengerWorkersCommand($signal));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(1, $signal->sent);
        self::assertStringContainsString('relu', $tester->getDisplay());
    }

    /**
     * L'échec cite sa cause et ce qu'il faut vérifier : c'est tout ce que
     * l'opérateur lit dans les journaux du Job.
     */
    public function testItFailsWithTheCauseWhenTheSignalIsNotSaved(): void
    {
        $tester = new CommandTester(new StopMessengerWorkersCommand(
            new RecordingRestartSignal(WorkerRestartSignalNotSavedException::refusedByTheStorage()),
        ));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('refusé', $display);
        self::assertStringContainsString('cache_items', $display);
        self::assertStringNotContainsString('[OK]', $display);
    }

    public function testItTakesNoArgument(): void
    {
        $definition = new StopMessengerWorkersCommand(new RecordingRestartSignal())->getDefinition();

        self::assertSame([], $definition->getArguments());
        self::assertSame([], $definition->getOptions());
    }
}
