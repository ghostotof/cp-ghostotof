<?php

declare(strict_types=1);

namespace App\Tests\Ai\Assistant\Domain\Exception;

use App\Ai\Assistant\Domain\Exception\InvalidConversationException;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\EventListener\ErrorListener;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Contre-audit, point 13 : l'ErrorListener du noyau journalise en `critical`
 * toute exception qui n'est pas une HttpExceptionInterface. Une conversation
 * refusée (422, et bientôt les bornes D6 de #262) est une erreur du client :
 * un `critical` par requête noierait les vraies pannes.
 */
final class InvalidConversationExceptionTest extends TestCase
{
    public function testTheKernelLogsARefusedConversationAtInfoNotCritical(): void
    {
        $handler = new TestHandler();
        $listener = new ErrorListener(null, new Logger('request', [$handler]));

        $listener->logKernelException(new ExceptionEvent(
            self::createStub(HttpKernelInterface::class),
            Request::create('/api/assistant/answers', 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
            new InvalidConversationException('La conversation est vide.'),
        ));

        self::assertCount(1, $handler->getRecords());
        self::assertSame(Level::Info, $handler->getRecords()[0]->level);
    }
}
