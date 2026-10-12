<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Messenger\Fixtures;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Fixture de QueuedMessagesNameTheirQueueFailureTest (issue #491) : routé
 * par son attribut vers `sync` seulement — rien n'est publié, la garde doit
 * le laisser passer.
 */
#[AsMessage('sync')]
final readonly class SynchronousAttributeMessage
{
}
