<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Incident\Support;

use App\Portfolio\Incident\Domain\Entity\Incident;
use App\Portfolio\Incident\Domain\Repository\IncidentRepositoryInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Uid\Uuid;

/**
 * Répertoire en mémoire (spec 0004 B3) : prouve que `IncidentAdministrator::reorder()`
 * charge son périmètre via `findAll()` et persiste en **un seul appel** à
 * `saveAll()` — jamais via `save()`, qui flusherait une fois par entité.
 * Sans Doctrine, comme tout ce qui teste la couche Application (DIP).
 */
final class InMemoryIncidentRepository implements IncidentRepositoryInterface
{
    /** @var list<Incident> */
    private readonly array $incidents;

    public int $saveCallCount = 0;

    /** @var list<list<Incident>> */
    public array $saveAllCalls = [];

    /**
     * @param list<Incident> $incidents
     */
    public function __construct(array $incidents = [])
    {
        $this->incidents = $incidents;
    }

    public function findOneById(Uuid $id): ?Incident
    {
        foreach ($this->incidents as $incident) {
            if ($incident->getId()->equals($id)) {
                return $incident;
            }
        }

        return null;
    }

    public function findByLocale(Locale $locale): array
    {
        return array_values(array_filter(
            $this->incidents,
            static fn (Incident $incident): bool => $incident->getLocale() === $locale,
        ));
    }

    public function findAll(): array
    {
        return $this->incidents;
    }

    public function findByTranslationGroup(Uuid $translationGroup): array
    {
        return array_values(array_filter(
            $this->incidents,
            static fn (Incident $incident): bool => $translationGroup->equals($incident->getTranslationGroup()),
        ));
    }

    public function save(Incident $incident): void
    {
        ++$this->saveCallCount;
    }

    public function saveAll(array $incidents): void
    {
        $this->saveAllCalls[] = $incidents;
    }

    public function remove(Incident $incident): void
    {
    }
}
