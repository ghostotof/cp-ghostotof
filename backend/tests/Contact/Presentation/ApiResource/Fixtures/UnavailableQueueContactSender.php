<?php

declare(strict_types=1);

namespace App\Tests\Contact\Presentation\ApiResource\Fixtures;

use App\Contact\Application\ContactMessageSenderInterface;
use App\Contact\Domain\Exception\ContactMessageQueueUnavailableException;
use App\Contact\Domain\ValueObject\ContactMessage;
use Symfony\Component\Messenger\Exception\TransportException;

/**
 * Expéditeur de contact dont la file est indisponible (issue #485), pour
 * ContactMessageResourceTest : ce que ContactMessageSender lève quand le
 * broker refuse ou ne répond pas.
 */
final class UnavailableQueueContactSender implements ContactMessageSenderInterface
{
    public function send(ContactMessage $message): void
    {
        throw new ContactMessageQueueUnavailableException(new TransportException('Connection refused'));
    }
}
