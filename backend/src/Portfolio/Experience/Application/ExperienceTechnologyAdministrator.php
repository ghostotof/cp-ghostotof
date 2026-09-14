<?php

declare(strict_types=1);

namespace App\Portfolio\Experience\Application;

use App\Portfolio\Experience\Domain\Entity\ExperienceTechnology;
use App\Portfolio\Experience\Domain\Exception\ExperienceTechnologyAlreadyExistsException;
use App\Portfolio\Experience\Domain\Exception\ExperienceTechnologyNotFoundException;
use App\Portfolio\Experience\Domain\Repository\ExperienceTechnologyRepositoryInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Uid\Uuid;

final readonly class ExperienceTechnologyAdministrator implements ExperienceTechnologyAdministratorInterface
{
    public function __construct(
        private ExperienceTechnologyRepositoryInterface $experienceTechnologyRepository,
    ) {
    }

    public function update(Uuid $id, string $name, float $years, ?string $iconKey, ?string $relatedTechnologyName, bool $secondary = false): ExperienceTechnology
    {
        $technology = $this->experienceTechnologyRepository->findOneById($id);

        if (null === $technology) {
            throw ExperienceTechnologyNotFoundException::forId($id);
        }

        $existingWithSameName = $this->experienceTechnologyRepository->findOneByName($name);

        // Comparaison par valeur (equals()) et non par identité (!==) : l'id
        // vient de l'URL, donc d'un Uuid fraîchement reconstruit, jamais la
        // même instance que celui porté par l'entité.
        if (null !== $existingWithSameName && !$existingWithSameName->getId()->equals($id)) {
            throw ExperienceTechnologyAlreadyExistsException::forName($name);
        }

        $technology->update($name, $years, $iconKey, $relatedTechnologyName, $secondary);

        try {
            $this->experienceTechnologyRepository->save($technology);
        } catch (UniqueConstraintViolationException) {
            // Cf. ExperienceTechnologyRegistrar::register() : la vérification
            // findOneByName() ci-dessus n'est pas atomique avec ce save(),
            // la contrainte unique en base reste le dernier rempart.
            throw ExperienceTechnologyAlreadyExistsException::forName($name);
        }

        return $technology;
    }

    public function delete(Uuid $id): void
    {
        $technology = $this->experienceTechnologyRepository->findOneById($id);

        if (null === $technology) {
            throw ExperienceTechnologyNotFoundException::forId($id);
        }

        $this->experienceTechnologyRepository->remove($technology);
    }
}
