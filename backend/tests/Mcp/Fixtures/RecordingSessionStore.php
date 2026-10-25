<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Fixtures;

use Mcp\Server\Session\SessionStoreInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Un store de sessions en mémoire qui garde **chaque** écriture reçue, dans
 * l'ordre : ce que le décorateur borné a réellement confié au store, et non
 * seulement le dernier état d'une ligne. Hors de `src/`, jamais chargé par
 * l'application.
 */
final class RecordingSessionStore implements SessionStoreInterface
{
    /** @var array<string, string> la valeur stockée, par identifiant RFC 4122 */
    private array $rows = [];

    /** @var list<string> chaque valeur écrite, dans l'ordre */
    private array $writes = [];

    /** @var list<string> les identifiants détruits, dans l'ordre */
    private array $destroyed = [];

    public function exists(Uuid $id): bool
    {
        return isset($this->rows[$id->toRfc4122()]);
    }

    public function read(Uuid $id): string|false
    {
        return $this->rows[$id->toRfc4122()] ?? false;
    }

    public function write(Uuid $id, string $data): bool
    {
        $this->rows[$id->toRfc4122()] = $data;
        $this->writes[] = $data;

        return true;
    }

    public function destroy(Uuid $id): bool
    {
        unset($this->rows[$id->toRfc4122()]);
        $this->destroyed[] = $id->toRfc4122();

        return true;
    }

    public function gc(): array
    {
        return [];
    }

    /** Pose une ligne telle qu'une requête précédente l'aurait laissée, sans la compter comme une écriture. */
    public function seed(Uuid $id, string $stored): void
    {
        $this->rows[$id->toRfc4122()] = $stored;
    }

    /** @return list<string> */
    public function writes(): array
    {
        return $this->writes;
    }

    /** @return list<string> */
    public function destroyed(): array
    {
        return $this->destroyed;
    }

    public function row(Uuid $id): string|false
    {
        return $this->rows[$id->toRfc4122()] ?? false;
    }
}
