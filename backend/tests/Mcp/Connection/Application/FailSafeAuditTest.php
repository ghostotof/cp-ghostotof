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
use Psr\Log\LoggerInterface;
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

    /** Le fichier où le `error_log()` de dernier recours est capté (sinon il pollue la sortie de PHPUnit). */
    private string $errorLogFile;

    private string $previousErrorLog;

    protected function setUp(): void
    {
        $this->handler = new TestHandler();

        $file = tempnam(sys_get_temp_dir(), 'failsafe');
        self::assertNotFalse($file);
        $this->errorLogFile = $file;
        $this->previousErrorLog = (string) \ini_get('error_log');
        ini_set('error_log', $file);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);
        unlink($this->errorLogFile);
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

    /**
     * La panne du journal lui-même (canal saturé, handler qui lève) ne
     * remonte pas non plus : le refus OAuth reste 400/401, jamais un 500.
     */
    public function testAFailureOfTheLoggerItselfIsSwallowed(): void
    {
        $logger = self::createStub(LoggerInterface::class);
        $logger->method('critical')->willThrowException(new RuntimeException('journal indisponible'));

        $ran = false;
        (new FailSafeAudit($logger))->attempt(static function () use (&$ran): never {
            $ran = true;

            throw new RuntimeException('panne de l\'audit');
        }, self::FAILURE_MESSAGE);

        self::assertTrue($ran, 'L\'écriture a été tentée, et rien n\'a remonté.');
    }

    /**
     * Dernier recours : le journal en panne ne laisse pas l'audit manqué sans
     * aucune trace. Un `error_log()` sans contexte : un message constant et la
     * classe de la panne du journal, jamais son message (il peut porter des
     * données).
     */
    public function testAFailureOfTheLoggerLeavesALastResortTraceWithTheClassOnly(): void
    {
        $logger = self::createStub(LoggerInterface::class);
        $logger->method('critical')->willThrowException(new RuntimeException('secret du journal'));

        (new FailSafeAudit($logger))->attempt(static function (): never {
            throw new RuntimeException('panne de l\'audit');
        }, self::FAILURE_MESSAGE);

        $trace = (string) file_get_contents($this->errorLogFile);

        self::assertStringContainsString('Audit failure could not be logged.', $trace);
        self::assertStringContainsString(RuntimeException::class, $trace);
        self::assertStringNotContainsString('secret du journal', $trace);
        self::assertStringNotContainsString('panne de l\'audit', $trace);
        self::assertStringNotContainsString(self::FAILURE_MESSAGE, $trace);
    }

    private function audit(): FailSafeAudit
    {
        return new FailSafeAudit(new Logger('app', [$this->handler]));
    }
}
