<?php

declare(strict_types=1);

namespace App\Portfolio\AnonymousCv\Application;

use App\Portfolio\AnonymousCv\Domain\Entity\AnonymousCvSection;
use App\Portfolio\AnonymousCv\Domain\Exception\AnonymousCvSectionNotFoundException;
use App\Portfolio\Shared\Domain\ValueObject\Locale;

interface AnonymousCvSectionAdministratorInterface
{
    public function create(
        Locale $locale,
        string $title,
        string $skills,
        int $yearsOfExperience,
        string $achievements,
        int $position,
    ): AnonymousCvSection;

    /**
     * @throws AnonymousCvSectionNotFoundException si l'id est inconnu
     */
    public function update(
        int $id,
        string $title,
        string $skills,
        int $yearsOfExperience,
        string $achievements,
        int $position,
    ): AnonymousCvSection;

    /**
     * @throws AnonymousCvSectionNotFoundException si l'id est inconnu
     */
    public function delete(int $id): void;
}
