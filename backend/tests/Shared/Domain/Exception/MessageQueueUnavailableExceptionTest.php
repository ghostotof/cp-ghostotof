<?php

declare(strict_types=1);

namespace App\Tests\Shared\Domain\Exception;

use App\Contact\Domain\Exception\ContactMessageQueueUnavailableException;
use App\Security\User\Domain\Exception\InvitationQueueUnavailableException;
use App\Shared\Domain\Exception\MessageQueueUnavailableException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Une panne de la file de messages rend un 503 (issue #485), sous un seul
 * `type` problem+json que le client reconnaît, chaque contexte ne fournissant
 * que sa formulation, littérale (Template Method).
 */
final class MessageQueueUnavailableExceptionTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<MessageQueueUnavailableException>}>
     */
    public static function contexts(): iterable
    {
        yield 'contact' => [ContactMessageQueueUnavailableException::class];
        yield 'invitation' => [InvitationQueueUnavailableException::class];
    }

    /**
     * @param class-string<MessageQueueUnavailableException> $class
     */
    #[DataProvider('contexts')]
    public function testEveryContextAnswersTheSameProblem(string $class): void
    {
        $exception = new $class(new RuntimeException('broker'));

        self::assertInstanceOf($class, $exception);
        self::assertSame(503, $exception->getStatus());
        self::assertSame('/errors/message-queue-unavailable', $exception->getType());
        self::assertSame($exception->getMessage(), $exception->getDetail());
    }

    public function testEachContextWordsItsOwnMessage(): void
    {
        $contact = new ContactMessageQueueUnavailableException(new RuntimeException('broker'));
        $invitation = new InvitationQueueUnavailableException(new RuntimeException('broker'));

        self::assertStringContainsString('message', $contact->getMessage());
        self::assertStringContainsString('invitation', $invitation->getMessage());
        self::assertNotSame($contact->getMessage(), $invitation->getMessage());
    }

    /**
     * Le message est le `detail` montré au client : il ne cite jamais la panne.
     * Elle reste en `previous`, que le noyau journalise (une erreur de canal
     * AMQP ne cite que le code et le transport, jamais le corps du message).
     *
     * @param class-string<MessageQueueUnavailableException> $class
     */
    #[DataProvider('contexts')]
    public function testTheCauseIsKeptForTheLogsButNeverShownToTheClient(string $class): void
    {
        $cause = new RuntimeException('Server channel error: 406, PRECONDITION_FAILED');
        $exception = new $class($cause);

        self::assertSame($cause, $exception->getPrevious());
        self::assertStringNotContainsString('PRECONDITION_FAILED', $exception->getMessage());
    }
}
