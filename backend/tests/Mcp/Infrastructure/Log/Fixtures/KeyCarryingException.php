<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Infrastructure\Log\Fixtures;

use JsonSerializable;
use RuntimeException;

/**
 * Une exception qui porte la clé d'une session sans la citer dans son
 * message (#731, constat n°1 de l'audit de sécurité de la PR 2).
 *
 * Monolog normalise une exception `JsonSerializable` par ce que renvoie
 * `jsonSerialize()` (`NormalizerFormatter::normalizeException()`), et non
 * par son message : un processeur qui ne réécrirait que les exceptions dont
 * un message cite l'identifiant la laisserait passer telle quelle, et la
 * clé sortirait dans la ligne JSON.
 */
final class KeyCarryingException extends RuntimeException implements JsonSerializable
{
    public function __construct(private readonly string $cacheKey)
    {
        parent::__construct('the database is gone');
    }

    /**
     * @return array{key: string}
     */
    public function jsonSerialize(): array
    {
        return ['key' => $this->cacheKey];
    }
}
