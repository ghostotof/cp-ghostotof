<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Infrastructure\ApiPlatform\Fixtures;

/**
 * Une ressource que le lecteur de payload reçoit avec des métadonnées
 * construites par le test (un `routePrefix`, un gabarit) : aucune ressource du
 * site n'a encore cette forme. Hors de `src/`, API Platform ne la charge pas.
 */
final readonly class PrefixedResource
{
}
