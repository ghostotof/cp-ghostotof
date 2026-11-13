<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application\Cimd;

/**
 * Le document de métadonnées d'un client CIMD tel que son hôte l'a servi
 * (#651) : le corps, pas encore analysé, et la durée de fraîcheur annoncée
 * (`max-age` de `Cache-Control`, en secondes), `null` quand l'hôte n'en
 * annonce aucune.
 */
final readonly class FetchedClientMetadata
{
    public function __construct(
        public string $body,
        public ?int $maxAge,
    ) {
    }
}
