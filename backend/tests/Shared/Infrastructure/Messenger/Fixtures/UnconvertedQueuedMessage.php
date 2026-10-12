<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Messenger\Fixtures;

/**
 * Message de la fixture AsyncRoutingProject (issue #491) : il ne nomme
 * aucune exception et part dans une file (`async` en `dev`, `failed` en
 * `prod`) — une panne du broker le laisserait en 500 générique, la garde
 * doit le signaler.
 */
final readonly class UnconvertedQueuedMessage
{
}
