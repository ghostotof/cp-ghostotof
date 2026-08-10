<?php

declare(strict_types=1);

namespace App\Portfolio\Experience\Application;

use App\Portfolio\Experience\Domain\Entity\ExperienceTechnology;
use App\Portfolio\Experience\Domain\Exception\ExperienceTechnologyAlreadyExistsException;
use App\Portfolio\Experience\Domain\Repository\ExperienceTechnologyRepositoryInterface;

final readonly class ExperienceTechnologyRegistrar implements ExperienceTechnologyRegistrarInterface
{
    public function __construct(
        private ExperienceTechnologyRepositoryInterface $experienceTechnologyRepository,
    ) {
    }

    public function register(string $name, float $years, ?string $iconKey, ?string $relatedTechnologyName): ExperienceTechnology
    {
        if (null !== $this->experienceTechnologyRepository->findOneByName($name)) {
            throw ExperienceTechnologyAlreadyExistsException::forName($name);
        }

        $technology = new ExperienceTechnology($name, $years, $iconKey, $relatedTechnologyName);

        $this->experienceTechnologyRepository->save($technology);

        return $technology;
    }
}
