<?php

declare(strict_types=1);

namespace App\Tests\Contact\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Post;
use App\Contact\Application\ContactMessageSenderInterface;
use App\Contact\Application\ContactRateLimiterInterface;
use App\Contact\Domain\Exception\ContactMessageQueueUnavailableException;
use App\Contact\Domain\Exception\ContactRateLimitExceededException;
use App\Contact\Domain\ValueObject\ContactMessage;
use App\Contact\Infrastructure\ApiPlatform\ContactMessageProcessor;
use App\Contact\Presentation\ApiResource\ContactMessageResource;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Le quota de contact n'est consommé que pour un message qui part (issue
 * #485) : un envoi qui échoue — broker indisponible — ne coûte rien au
 * visiteur, qui peut réessayer une fois la file revenue. Un honeypot rempli
 * le consomme, comme un envoi : le bot croit avoir réussi.
 */
final class ContactMessageProcessorTest extends TestCase
{
    /** @var list<string> */
    private array $events = [];

    public function testAMessageThatLeavesConsumesTheQuotaAfterItIsSent(): void
    {
        $this->processor(sending: true)->process($this->submission(), new Post(), context: ['request' => $this->request()]);

        self::assertSame(['check 203.0.113.10', 'send', 'record 203.0.113.10'], $this->events);
    }

    public function testAMessageThatFailsToLeaveConsumesNothing(): void
    {
        try {
            $this->processor(sending: false)->process($this->submission(), new Post(), context: ['request' => $this->request()]);
            self::fail('L\'échec de l\'envoi doit remonter.');
        } catch (ContactMessageQueueUnavailableException) {
            self::assertSame(['check 203.0.113.10', 'send'], $this->events);
        }
    }

    public function testAFilledHoneypotConsumesTheQuotaWithoutSending(): void
    {
        $submission = $this->submission();
        $submission->website = 'https://spam.example';

        $this->processor(sending: true)->process($submission, new Post(), context: ['request' => $this->request()]);

        self::assertSame(['check 203.0.113.10', 'record 203.0.113.10'], $this->events);
    }

    public function testAnExhaustedQuotaRefusesBeforeAnySend(): void
    {
        try {
            $this->processor(sending: true, exhausted: true)->process($this->submission(), new Post(), context: ['request' => $this->request()]);
            self::fail('Le quota est épuisé.');
        } catch (ContactRateLimitExceededException) {
            self::assertSame(['check 203.0.113.10'], $this->events);
        }
    }

    private function processor(bool $sending, bool $exhausted = false): ContactMessageProcessor
    {
        $events = &$this->events;

        $sender = self::createStub(ContactMessageSenderInterface::class);
        $sender->method('send')->willReturnCallback(static function (ContactMessage $message) use (&$events, $sending): void {
            $events[] = 'send';
            if (!$sending) {
                throw new ContactMessageQueueUnavailableException(new RuntimeException('broker'));
            }
        });

        $limiter = self::createStub(ContactRateLimiterInterface::class);
        $limiter->method('assertWithinQuota')->willReturnCallback(static function (string $client) use (&$events, $exhausted): void {
            $events[] = 'check '.$client;
            if ($exhausted) {
                throw new ContactRateLimitExceededException(new DateTimeImmutable('+1 hour'));
            }
        });
        $limiter->method('record')->willReturnCallback(static function (string $client) use (&$events): void {
            $events[] = 'record '.$client;
        });

        return new ContactMessageProcessor($sender, $limiter);
    }

    private function submission(): ContactMessageResource
    {
        $submission = new ContactMessageResource();
        $submission->name = 'Jane Doe';
        $submission->email = 'jane@example.com';
        $submission->message = 'Bonjour, ceci est un message.';

        return $submission;
    }

    private function request(): Request
    {
        return Request::create('/api/contact', 'POST', server: ['REMOTE_ADDR' => '203.0.113.10']);
    }
}
