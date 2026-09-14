<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Shared\Support;

use App\Portfolio\Shared\Domain\Orderable;

/**
 * `Orderable` minimal, sans Doctrine ni locale : le test d'`OrderAssigner` ne
 * doit dépendre d'aucun des neuf contextes qui l'utilisent — en prendre un au
 * hasard ferait de son test l'otage de ce contexte-là. Garde trace des appels
 * à `moveToPosition()` pour pouvoir affirmer qu'une entité hors périmètre n'a
 * jamais été touchée.
 */
final class FakeOrderable implements Orderable
{
    private ?int $position = null;

    private int $moveCount = 0;

    public function __construct(private readonly string $key)
    {
    }

    public function orderingKey(): string
    {
        return $this->key;
    }

    public function moveToPosition(int $position): void
    {
        $this->position = $position;
        ++$this->moveCount;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function wasMoved(): bool
    {
        return $this->moveCount > 0;
    }
}
