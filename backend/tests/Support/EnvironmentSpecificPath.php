<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Un écart voulu entre environnements, dans la définition d'un service de
 * sécurité (issue #698) : le chemin retiré de la comparaison avec `test`, la
 * valeur qu'il doit avoir dans chaque environnement, pourquoi, et le test qui
 * énonce la règle — écrit en `::class` pour qu'un renommage fasse rougir
 * PHPStan.
 */
final readonly class EnvironmentSpecificPath
{
    /**
     * @param list<int|string>          $path
     * @param array<string, mixed>      $expected environnement => valeur attendue au bout du chemin
     * @param class-string<TestCase>    $pinnedBy
     */
    public function __construct(
        public string $service,
        public array $path,
        public array $expected,
        public string $reason,
        public string $pinnedBy,
    ) {
    }

    public function describe(): string
    {
        return $this->service.' '.KeyPaths::describe($this->path);
    }
}
