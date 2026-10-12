<?php

declare(strict_types=1);

namespace App\Portfolio\Experience\Application;

use App\Portfolio\Experience\Domain\Entity\ExperienceTechnology;
use App\Portfolio\Experience\Domain\Exception\ExperienceTechnologyAlreadyExistsException;
use App\Portfolio\Experience\Domain\Repository\ExperienceTechnologyRepositoryInterface;
use App\Portfolio\Experience\Domain\ValueObject\ExperienceYears;
use App\Portfolio\Experience\Domain\ValueObject\TechnologyName;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final readonly class ExperienceTechnologyRegistrar implements ExperienceTechnologyRegistrarInterface
{
    public function __construct(
        private ExperienceTechnologyRepositoryInterface $experienceTechnologyRepository,
    ) {
    }

    public function register(TechnologyName $name, ExperienceYears $years, ?string $iconKey, ?string $relatedTechnologyName, bool $secondary = false): ExperienceTechnology
    {
        if (null !== $this->experienceTechnologyRepository->findOneByName($name->value)) {
            throw ExperienceTechnologyAlreadyExistsException::forName($name->value);
        }

        $technology = new ExperienceTechnology($name, $years, $iconKey, $relatedTechnologyName, $secondary);

        try {
            $this->experienceTechnologyRepository->save($technology);
        } catch (UniqueConstraintViolationException) {
            // Deux requêtes concurrentes ont passé le findOneByName() ci-dessus
            // avant que l'une des deux ne persiste : la contrainte unique en
            // base est le dernier rempart, on la traduit en exception métier
            // plutôt que de laisser remonter un 500 Doctrine brut.
            throw ExperienceTechnologyAlreadyExistsException::forName($name->value);
        }

        return $technology;
    }
}
