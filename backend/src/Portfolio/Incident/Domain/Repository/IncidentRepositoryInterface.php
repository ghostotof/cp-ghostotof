<?php

declare(strict_types=1);

namespace App\Portfolio\Incident\Domain\Repository;

use App\Portfolio\Incident\Domain\Entity\Incident;
use App\Portfolio\Shared\Domain\ValueObject\Locale;

/**
 * Abstraction (DIP) dont dépend la couche Application : elle ne connaît jamais
 * Doctrine directement.
 */
interface IncidentRepositoryInterface
{
    public function findOneById(int $id): ?Incident;

    /**
     * @return list<Incident> triés par position ASC
     */
    public function findByLocale(Locale $locale): array;

    /**
     * @return list<Incident> triés par locale puis position ASC
     */
    public function findAll(): array;

    public function save(Incident $incident): void;

    public function remove(Incident $incident): void;
}
