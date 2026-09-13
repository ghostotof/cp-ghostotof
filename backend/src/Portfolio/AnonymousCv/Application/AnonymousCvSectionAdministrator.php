<?php

declare(strict_types=1);

namespace App\Portfolio\AnonymousCv\Application;

use App\Portfolio\AnonymousCv\Domain\Entity\AnonymousCvSection;
use App\Portfolio\AnonymousCv\Domain\Exception\AnonymousCvSectionNotFoundException;
use App\Portfolio\AnonymousCv\Domain\Repository\AnonymousCvSectionRepositoryInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;

final readonly class AnonymousCvSectionAdministrator implements AnonymousCvSectionAdministratorInterface
{
    public function __construct(
        private AnonymousCvSectionRepositoryInterface $sectionRepository,
    ) {
    }

    public function create(
        Locale $locale,
        string $title,
        string $skills,
        int $yearsOfExperience,
        string $achievements,
        int $position,
    ): AnonymousCvSection {
        $section = new AnonymousCvSection($locale, $title, $skills, $yearsOfExperience, $achievements, $position);

        $this->sectionRepository->save($section);

        return $section;
    }

    public function update(
        int $id,
        string $title,
        string $skills,
        int $yearsOfExperience,
        string $achievements,
        int $position,
    ): AnonymousCvSection {
        $section = $this->sectionRepository->findOneById($id);

        if (null === $section) {
            throw AnonymousCvSectionNotFoundException::forId($id);
        }

        $section->update($title, $skills, $yearsOfExperience, $achievements, $position);
        $this->sectionRepository->save($section);

        return $section;
    }

    public function delete(int $id): void
    {
        $section = $this->sectionRepository->findOneById($id);

        if (null === $section) {
            throw AnonymousCvSectionNotFoundException::forId($id);
        }

        $this->sectionRepository->remove($section);
    }
}
