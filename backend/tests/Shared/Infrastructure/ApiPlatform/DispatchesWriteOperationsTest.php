<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Shared\Infrastructure\ApiPlatform\DispatchesWriteOperations;
use App\Shared\Infrastructure\ApiPlatform\UnsupportedOperationException;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Uid\Uuid;

/**
 * Les Processors du backoffice ne déclarent que leurs trois actions ; le trait
 * choisit laquelle selon l'opération (issue #338). Une opération que la
 * ressource ne déclare pas est un défaut de câblage, jamais une requête : elle
 * est refusée sous un nom dédié, sans qu'aucune action ne parte — surtout pas
 * traitée comme un Post.
 */
final class DispatchesWriteOperationsTest extends TestCase
{
    private const string ID = '019968a0-0000-7000-8000-000000000001';

    public function testPostCreatesAndReturnsTheCreatedResource(): void
    {
        $processor = new RecordingProcessor();
        $data = new stdClass();

        self::assertSame($processor->created, $processor->process($data, new Post()));
        self::assertSame([['create', $data]], $processor->calls);
    }

    public function testPutUpdatesTheEntryOfTheUriAndReturnsTheUpdatedResource(): void
    {
        $processor = new RecordingProcessor();
        $data = new stdClass();

        self::assertSame($processor->updated, $processor->process($data, new Put(), ['id' => self::ID]));
        self::assertSame([['update', self::ID, $data]], $processor->calls);
    }

    public function testDeleteDeletesTheEntryOfTheUriAndReturnsNothing(): void
    {
        $processor = new RecordingProcessor();

        self::assertNull($processor->process(new stdClass(), new Delete(), ['id' => self::ID]));
        self::assertSame([['delete', self::ID]], $processor->calls);
    }

    public function testAnUndeclaredOperationIsRefusedUnderItsOwnNameWithoutAnyAction(): void
    {
        $processor = new RecordingProcessor();

        try {
            $processor->process(new stdClass(), new Patch(), ['id' => self::ID]);
            self::fail('Une opération Patch ne doit jamais être traitée.');
        } catch (UnsupportedOperationException $exception) {
            self::assertStringContainsString(Patch::class, $exception->getMessage());
        }

        self::assertSame([], $processor->calls);
    }
}

/**
 * Un Processor minimal qui note chaque action reçue, dans l'ordre.
 *
 * @internal
 */
final class RecordingProcessor
{
    /** @use DispatchesWriteOperations<stdClass> */
    use DispatchesWriteOperations;

    /** @var list<list<mixed>> */
    public array $calls = [];

    public readonly stdClass $created;

    public readonly stdClass $updated;

    public function __construct()
    {
        $this->created = new stdClass();
        $this->updated = new stdClass();
    }

    private function create(mixed $data): stdClass
    {
        $this->calls[] = ['create', $data];

        return $this->created;
    }

    private function update(Uuid $id, mixed $data): stdClass
    {
        $this->calls[] = ['update', $id->toRfc4122(), $data];

        return $this->updated;
    }

    private function delete(Uuid $id): void
    {
        $this->calls[] = ['delete', $id->toRfc4122()];
    }
}
