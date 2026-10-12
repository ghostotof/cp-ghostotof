<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Messenger\Fixtures;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Fixture de QueuedMessagesNameTheirQueueFailureTest (issue #491) : routé
 * vers `async` par son attribut, que SendersLocator lit à l'envoi, sans
 * nommer d'exception — la garde doit le signaler dans chaque environnement.
 */
#[AsMessage('async')]
final readonly class AttributeRoutedMessage
{
}
