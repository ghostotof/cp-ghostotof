<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Messenger\Fixtures;

use App\Shared\Application\Message\MessengerProbeMessage;
use App\Shared\Infrastructure\Messenger\MessengerProbeHandler;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Bus de test de CacheMarkedMessengerProbeTest (issue #470) : note chaque
 * message, et le passe au handler s'il y en a un — le worker qui consomme.
 */
final class RecordingBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $dispatched = [];

    public function __construct(private readonly ?MessengerProbeHandler $handler)
    {
    }

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->dispatched[] = $message;
        if ($message instanceof MessengerProbeMessage && null !== $this->handler) {
            ($this->handler)($message);
        }

        return new Envelope($message, $stamps);
    }

    /**
     * @return list<string>
     */
    public function probeIds(): array
    {
        return array_map(
            static fn (object $message): string => $message instanceof MessengerProbeMessage ? $message->probeId : '',
            $this->dispatched,
        );
    }
}
