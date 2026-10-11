<?php

declare(strict_types=1);

namespace App\Tests\Shared\Presentation\Command\Fixtures;

use App\Shared\Application\MessengerRoundTripProbeInterface;

/**
 * Sonde de MessengerProbeCommandTest (issue #470) : rend la réponse voulue et
 * note les délais demandés.
 */
final class RecordingProbe implements MessengerRoundTripProbeInterface
{
    /** @var list<int> */
    public array $timeouts = [];

    public function __construct(private readonly bool $handled)
    {
    }

    public function probe(int $timeoutSeconds): bool
    {
        $this->timeouts[] = $timeoutSeconds;

        return $this->handled;
    }
}
