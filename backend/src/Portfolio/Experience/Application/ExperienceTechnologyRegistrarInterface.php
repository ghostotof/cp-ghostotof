<?php

declare(strict_types=1);

namespace App\Portfolio\Experience\Application;

use App\Portfolio\Experience\Domain\Entity\ExperienceTechnology;
use App\Portfolio\Experience\Domain\Exception\ExperienceTechnologyAlreadyExistsException;
use App\Portfolio\Experience\Domain\ValueObject\ExperienceYears;
use App\Portfolio\Experience\Domain\ValueObject\TechnologyName;

interface ExperienceTechnologyRegistrarInterface
{
    /**
     * @throws ExperienceTechnologyAlreadyExistsException si le nom est déjà utilisé
     */
    public function register(TechnologyName $name, ExperienceYears $years, ?string $iconKey, ?string $relatedTechnologyName, bool $secondary = false): ExperienceTechnology;
}
