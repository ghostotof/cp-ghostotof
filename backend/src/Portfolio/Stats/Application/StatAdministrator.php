<?php

declare(strict_types=1);

namespace App\Portfolio\Stats\Application;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Portfolio\Stats\Domain\Entity\Stat;
use App\Portfolio\Stats\Domain\Exception\StatNotFoundException;
use App\Portfolio\Stats\Domain\Repository\StatRepositoryInterface;

final readonly class StatAdministrator implements StatAdministratorInterface
{
    public function __construct(
        private StatRepositoryInterface $statRepository,
    ) {
    }

    public function create(Locale $locale, string $value, string $label, string $iconKey, int $position): Stat
    {
        $stat = new Stat($locale, $value, $label, $iconKey, $position);

        $this->statRepository->save($stat);

        return $stat;
    }

    public function update(int $id, string $value, string $label, string $iconKey, int $position): Stat
    {
        $stat = $this->statRepository->findOneById($id);

        if (null === $stat) {
            throw StatNotFoundException::forId($id);
        }

        $stat->update($value, $label, $iconKey, $position);
        $this->statRepository->save($stat);

        return $stat;
    }

    public function delete(int $id): void
    {
        $stat = $this->statRepository->findOneById($id);

        if (null === $stat) {
            throw StatNotFoundException::forId($id);
        }

        $this->statRepository->remove($stat);
    }
}
