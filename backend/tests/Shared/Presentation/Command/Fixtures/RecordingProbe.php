<?php

declare(strict_types=1);

namespace App\Tests\Shared\Presentation\Command\Fixtures;

use App\Shared\Application\MessengerRoundTripProbeInterface;
use Throwable;

/**
 * Sonde de MessengerProbeCommandTest (issue #470) : rend la réponse voulue et
 * note les délais demandés.
 */
final class RecordingProbe implements MessengerRoundTripProbeInterface
{
    /** @var list<int> */
    public array $timeouts = [];

    public function __construct(
        private readonly bool $handled,
        private readonly ?Throwable $failure = null,
    ) {
    }

    public function probe(int $timeoutSeconds): bool
    {
        $this->timeouts[] = $timeoutSeconds;
        if (null !== $this->failure) {
            throw $this->failure;
        }

        return $this->handled;
    }
}
