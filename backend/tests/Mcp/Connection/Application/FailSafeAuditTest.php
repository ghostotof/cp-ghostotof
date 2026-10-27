<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Application;

use App\Mcp\Connection\Application\FailSafeAudit;
use Error;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * FailSafeAudit (#726, S1) : l'écriture tentée, sa panne journalisée en
 * `critical` avec la classe seule — jamais le message, qui pourrait citer
 * l'artefact —, et rien ne remonte à l'appelant.
 */
final class FailSafeAuditTest extends TestCase
{
    private const string FAILURE_MESSAGE = 'Something not audited.';

    private TestHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new TestHandler();
    }

    /** Le cas nominal : l'écriture a lieu, une fois, et rien n'est journalisé. */
    public function testTheWriteRunsOnceAndNothingIsLogged(): void
    {
        $calls = 0;

        $this->audit()->attempt(static function () use (&$calls): void {
            ++$calls;
        }, self::FAILURE_MESSAGE);

        self::assertSame(1, $calls);
        self::assertSame([], $this->handler->getRecords());
    }

    /**
     * Une panne, exception comme erreur du moteur (`Throwable`), ne remonte
     * pas : une ligne `critical`, le message constant de l'appelant, la
     * classe seule dans le contexte — ni le message de la panne ni l'exception.
     */
    public function testAFailureIsLoggedCriticalWithItsClassOnly(): void
    {
        $failures = [
            new RuntimeException('code=artefact-cite-1'),
            new Error('jeton artefact-cite-2'),
        ];

        foreach ($failures as $failure) {
            $this->audit()->attempt(static function () use ($failure): never {
                throw $failure;
            }, self::FAILURE_MESSAGE);
        }

        $records = $this->handler->getRecords();
        self::assertCount(2, $records);
        foreach ($records as $index => $record) {
            self::assertSame(Level::Critical, $record->level);
            self::assertSame(self::FAILURE_MESSAGE, $record->message);
            self::assertSame(['exceptionClass' => $failures[$index]::class], $record->context);
        }

        $journal = json_encode(array_map(static fn (LogRecord $record): array => $record->toArray(), $records), \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('artefact-cite', $journal);
    }

    /** Chaque tentative est indépendante : une panne n'empêche pas la suivante. */
    public function testAFailureDoesNotSkipTheNextAttempt(): void
    {
        $audit = $this->audit();
        $written = [];

        foreach (['première', 'seconde'] as $line) {
            $audit->attempt(static function () use ($line, &$written): void {
                if ('première' === $line) {
                    throw new RuntimeException('panne');
                }

                $written[] = $line;
            }, self::FAILURE_MESSAGE);
        }

        self::assertSame(['seconde'], $written);
        self::assertCount(1, $this->handler->getRecords());
    }

    private function audit(): FailSafeAudit
    {
        return new FailSafeAudit(new Logger('app', [$this->handler]));
    }
}
