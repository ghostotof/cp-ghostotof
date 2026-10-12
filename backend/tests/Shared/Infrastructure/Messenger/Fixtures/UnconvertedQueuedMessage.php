<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Messenger\Fixtures;

/**
 * Message de la fixture AsyncRoutingProject (issue #491) : il ne nomme
 * aucune exception, une file en panne le laisserait en 500 générique.
 */
final readonly class UnconvertedQueuedMessage
{
}
