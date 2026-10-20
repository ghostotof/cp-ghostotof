<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Infrastructure\Log;

use App\Mcp\Infrastructure\Log\SdkChannelRedactor;
use App\Mcp\Presentation\Prompt\RoleNeutralisationFailedException;
use DateTimeImmutable;
use JsonException;
use LogicException;
use Mcp\Exception\RegistryException;
use Mcp\Exception\ToolNotFoundException;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Throwable;

/**
 * Le pendant de SdkChannelRecordsTest, qui provoque les fautes du client sur le
 * vrai serveur : ce que le processeur laisse passer tel quel. Une panne du
 * serveur garde son niveau — rétrogradée, elle disparaîtrait sous le plafond
 * `warning` du handler de production.
 */
final class SdkChannelRedactorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, Throwable}>
     */
    public static function serverFaults(): iterable
    {
        yield 'échec d\'encodage d\'une réponse' => ['Failed to encode response to JSON.', new JsonException('Malformed UTF-8')];
        yield 'défaut de câblage d\'un outil' => ['Unhandled error during tool execution', new LogicException('wiring')];
        yield 'neutralisation du rôle en échec' => ['Unexpected error while handling prompt "present_for_role": "x".', RoleNeutralisationFailedException::fromPcre()];
        yield 'erreur du registre hors argument absent' => ['Unexpected error while handling prompt "p": "x".', RegistryException::internalError('Handler not callable.')];
    }

    #[DataProvider('serverFaults')]
    public function testAServerFaultKeepsItsLevel(string $message, Throwable $exception): void
    {
        $record = (new SdkChannelRedactor())($this->record(Level::Error, $message, ['exception' => $exception]));

        self::assertSame(Level::Error, $record->level);
    }

    public function testAClientFaultBelowWarningIsLeftAtItsLevel(): void
    {
        $record = (new SdkChannelRedactor())($this->record(Level::Info, 'Tool not found', ['exception' => new ToolNotFoundException('x')]));

        self::assertSame(Level::Info, $record->level);
    }

    public function testAClientFaultIsDowngradedToNotice(): void
    {
        $record = (new SdkChannelRedactor())($this->record(Level::Error, 'Tool not found', ['exception' => new ToolNotFoundException('x')]));

        self::assertSame(Level::Notice, $record->level);
    }

    public function testAnExceptionBecomesItsBoundedFormWithoutTrace(): void
    {
        $exception = new LogicException(str_repeat('é', 300), 7, new JsonException('inner'));

        $record = (new SdkChannelRedactor())($this->record(Level::Error, 'x', ['exception' => $exception]));

        $normalized = $record->context['exception'];
        self::assertIsArray($normalized);
        self::assertSame(LogicException::class, $normalized['class']);
        self::assertSame(str_repeat('é', 256).'… (300 characters)', $normalized['message']);
        self::assertSame(7, $normalized['code']);
        self::assertSame(__FILE__.':'.$exception->getLine(), $normalized['file']);
        self::assertSame(['class', 'message', 'code', 'file', 'previous'], array_keys($normalized));
        self::assertIsArray($normalized['previous']);
        self::assertSame(JsonException::class, $normalized['previous']['class']);
    }

    public function testEachContextValueIsBoundedOrWithheld(): void
    {
        $record = (new SdkChannelRedactor())($this->record(Level::Debug, str_repeat('m', 257), [
            'message' => 'raw',
            'session_id' => '0199f1e2-0000-7000-8000-000000000000',
            'name' => str_repeat('n', 257),
            'limit' => 64,
            'ok' => true,
            'nothing' => null,
            'object' => new stdClass(),
            'list' => ['a' => 'b'],
            'at' => new DateTimeImmutable(),
        ]));

        self::assertSame(str_repeat('m', 256).'… (257 characters)', $record->message);
        self::assertSame([
            'message' => SdkChannelRedactor::WITHHELD,
            'session_id' => SdkChannelRedactor::WITHHELD,
            'name' => str_repeat('n', 256).'… (257 characters)',
            'limit' => 64,
            'ok' => true,
            'nothing' => null,
            'object' => stdClass::class,
            'list' => 'array',
            'at' => DateTimeImmutable::class,
        ], $record->context);
    }

    public function testInvalidUtf8IsScrubbedRatherThanFailingTheRecord(): void
    {
        $record = (new SdkChannelRedactor())($this->record(Level::Warning, "bad \xC3", ['name' => "\xFF"]));

        self::assertSame('bad ?', $record->message);
        self::assertSame('?', $record->context['name']);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function record(Level $level, string $message, array $context): LogRecord
    {
        return new LogRecord(new DateTimeImmutable(), 'mcp', $level, $message, $context);
    }
}
