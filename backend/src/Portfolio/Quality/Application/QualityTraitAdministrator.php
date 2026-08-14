<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Application;

use App\Portfolio\Quality\Domain\Entity\QualityTrait as QualityTraitEntity;
use App\Portfolio\Quality\Domain\Exception\QualityTraitNotFoundException;
use App\Portfolio\Quality\Domain\Repository\QualityTraitRepositoryInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;

final readonly class QualityTraitAdministrator implements QualityTraitAdministratorInterface
{
    public function __construct(
        private QualityTraitRepositoryInterface $qualityTraitRepository,
    ) {
    }

    public function create(Locale $locale, string $label, int $position): QualityTraitEntity
    {
        $trait = new QualityTraitEntity($locale, $label, $position);

        $this->qualityTraitRepository->save($trait);

        return $trait;
    }

    public function update(int $id, string $label, int $position): QualityTraitEntity
    {
        $trait = $this->qualityTraitRepository->findOneById($id);

        if (null === $trait) {
            throw QualityTraitNotFoundException::forId($id);
        }

        $trait->update($label, $position);
        $this->qualityTraitRepository->save($trait);

        return $trait;
    }

    public function delete(int $id): void
    {
        $trait = $this->qualityTraitRepository->findOneById($id);

        if (null === $trait) {
            throw QualityTraitNotFoundException::forId($id);
        }

        $this->qualityTraitRepository->remove($trait);
    }
}
