<?php

declare(strict_types=1);

namespace App\Tests\Shared\Presentation\Command\Fixtures;

use App\Shared\Application\WorkerRestartSignalInterface;
use Throwable;

/**
 * Signal de StopMessengerWorkersCommandTest (issue #528) : compte les envois
 * et lève l'échec voulu.
 */
final class RecordingRestartSignal implements WorkerRestartSignalInterface
{
    public int $sent = 0;

    public function __construct(
        private readonly ?Throwable $failure = null,
    ) {
    }

    public function send(): void
    {
        ++$this->sent;
        if (null !== $this->failure) {
            throw $this->failure;
        }
    }
}
