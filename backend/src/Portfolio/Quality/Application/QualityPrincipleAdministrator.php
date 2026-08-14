<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Application;

use App\Portfolio\Quality\Domain\Entity\QualityPrinciple;
use App\Portfolio\Quality\Domain\Exception\QualityPrincipleNotFoundException;
use App\Portfolio\Quality\Domain\Repository\QualityPrincipleRepositoryInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;

final readonly class QualityPrincipleAdministrator implements QualityPrincipleAdministratorInterface
{
    public function __construct(
        private QualityPrincipleRepositoryInterface $qualityPrincipleRepository,
    ) {
    }

    public function create(Locale $locale, string $title, string $description, string $iconKey, int $position): QualityPrinciple
    {
        $principle = new QualityPrinciple($locale, $title, $description, $iconKey, $position);

        $this->qualityPrincipleRepository->save($principle);

        return $principle;
    }

    public function update(int $id, string $title, string $description, string $iconKey, int $position): QualityPrinciple
    {
        $principle = $this->qualityPrincipleRepository->findOneById($id);

        if (null === $principle) {
            throw QualityPrincipleNotFoundException::forId($id);
        }

        $principle->update($title, $description, $iconKey, $position);
        $this->qualityPrincipleRepository->save($principle);

        return $principle;
    }

    public function delete(int $id): void
    {
        $principle = $this->qualityPrincipleRepository->findOneById($id);

        if (null === $principle) {
            throw QualityPrincipleNotFoundException::forId($id);
        }

        $this->qualityPrincipleRepository->remove($principle);
    }
}
