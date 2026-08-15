<?php

declare(strict_types=1);

namespace App\Portfolio\Experience\Application;

use App\Portfolio\Experience\Domain\Entity\ExperienceTechnology;
use App\Portfolio\Experience\Domain\Exception\ExperienceTechnologyAlreadyExistsException;
use App\Portfolio\Experience\Domain\Repository\ExperienceTechnologyRepositoryInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

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

        try {
            $this->experienceTechnologyRepository->save($technology);
        } catch (UniqueConstraintViolationException) {
            // Deux requêtes concurrentes ont passé le findOneByName() ci-dessus
            // avant que l'une des deux ne persiste : la contrainte unique en
            // base est le dernier rempart, on la traduit en exception métier
            // plutôt que de laisser remonter un 500 Doctrine brut.
            throw ExperienceTechnologyAlreadyExistsException::forName($name);
        }

        return $technology;
    }
}
