<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Sonde d'injection d'objet : compte ses désérialisations. Un gadget réel
 * exécuterait du code à cet endroit ; aucune enveloppe Messenger (issue #444)
 * ni aucune valeur de cache en base (issue #468) non authentifiée ne doit
 * l'atteindre.
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
