<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Presentation\Tool;

use App\Mcp\Presentation\Tool\JsonToolResult;
use App\Mcp\Presentation\Tool\ToolPayloadShapeException;
use PHPUnit\Framework\TestCase;

/**
 * La forme du payload est celle que la méthode annonce (relecture de la
 * tâche 2) : sans contrôle, un objet passé à `forCollection()` était
 * enveloppé dans `items` en silence, et une liste passée à `forObject()`
 * sortait en tableau JSON — la spécification MCP veut un objet pour
 * `structuredContent`, que le client refuse.
 */
final class JsonToolResultTest extends TestCase
{
    public function testACollectionIsWrappedInItems(): void
    {
        self::assertSame(['items' => [['a' => 1], ['a' => 2]]], JsonToolResult::forCollection([['a' => 1], ['a' => 2]])->structuredContent);
    }

    public function testAnEmptyCollectionIsAnEmptyItemsList(): void
    {
        self::assertSame(['items' => []], JsonToolResult::forCollection([])->structuredContent);
    }

    public function testAnObjectPassedAsACollectionIsRefused(): void
    {
        $this->expectException(ToolPayloadShapeException::class);

        JsonToolResult::forCollection(['title' => 'Un objet']);
    }

    public function testAnObjectIsServedAsIs(): void
    {
        self::assertSame(['title' => 'Un objet'], JsonToolResult::forObject(['title' => 'Un objet'])->structuredContent);
    }

    public function testAListPassedAsAnObjectIsRefused(): void
    {
        $this->expectException(ToolPayloadShapeException::class);

        JsonToolResult::forObject([['a' => 1]]);
    }

    /**
     * `[]` s'encoderait en tableau JSON, pas en objet : refusé aussi.
     */
    public function testAnEmptyPayloadPassedAsAnObjectIsRefused(): void
    {
        $this->expectException(ToolPayloadShapeException::class);

        JsonToolResult::forObject([]);
    }
}
