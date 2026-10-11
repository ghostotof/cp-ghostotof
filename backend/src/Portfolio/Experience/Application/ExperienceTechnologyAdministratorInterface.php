<?php

declare(strict_types=1);

namespace App\Portfolio\Experience\Application;

use App\Portfolio\Experience\Domain\Entity\ExperienceTechnology;
use App\Portfolio\Experience\Domain\Exception\ExperienceTechnologyAlreadyExistsException;
use App\Portfolio\Experience\Domain\Exception\ExperienceTechnologyNotFoundException;
use App\Portfolio\Experience\Domain\ValueObject\ExperienceYears;
use App\Portfolio\Experience\Domain\ValueObject\TechnologyName;
use Symfony\Component\Uid\Uuid;

interface ExperienceTechnologyAdministratorInterface
{
    /**
     * @throws ExperienceTechnologyNotFoundException si l'id est inconnu
     * @throws ExperienceTechnologyAlreadyExistsException si le nom est déjà utilisé par une autre technologie
     */
    public function update(Uuid $id, TechnologyName $name, ExperienceYears $years, ?string $iconKey, ?string $relatedTechnologyName, bool $secondary = false): ExperienceTechnology;

    /**
     * @throws ExperienceTechnologyNotFoundException si l'id est inconnu
     */
    public function delete(Uuid $id): void;
}
