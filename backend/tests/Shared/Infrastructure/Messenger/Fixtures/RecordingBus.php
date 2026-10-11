<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Messenger\Fixtures;

use App\Shared\Application\Message\MessengerProbeMessage;
use App\Shared\Infrastructure\Messenger\MessengerProbeHandler;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * Bus de test de CacheMarkedMessengerProbeTest (issue #470) : note chaque
 * message, et le passe au handler s'il y en a un — le worker qui consomme.
 */
final class RecordingBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $dispatched = [];

    /**
     * @param MockClock|null  $clock            horloge avancée de `$dispatchSeconds` à chaque envoi
     *                                          (une publication AMQP lente)
     * @param Throwable|null  $failure          levée par chaque envoi (broker en panne)
     */
    public function __construct(
        private readonly ?MessengerProbeHandler $handler,
        private readonly ?MockClock $clock = null,
        private readonly int $dispatchSeconds = 0,
        private readonly ?Throwable $failure = null,
    ) {
    }

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->clock?->sleep($this->dispatchSeconds);
        if (null !== $this->failure) {
            throw $this->failure;
        }
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
