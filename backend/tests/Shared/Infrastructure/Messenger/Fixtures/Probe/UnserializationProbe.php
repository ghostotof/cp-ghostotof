<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Messenger\Fixtures\Probe;

/**
 * Sonde d'injection d'objet (issue #444) : compte ses désérialisations. Un
 * gadget réel exécuterait du code à cet endroit ; aucune enveloppe non
 * authentifiée ne doit l'atteindre.
 */
final class UnserializationProbe
{
    public static int $unserializations = 0;

    public string $payload = 'gadget';

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        ++self::$unserializations;
        $payload = $data['payload'] ?? '';
        $this->payload = \is_string($payload) ? $payload : '';
    }
}
