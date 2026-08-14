<?php

declare(strict_types=1);

namespace App\Portfolio\Experience\Application;

use App\Portfolio\Experience\Domain\Entity\ExperienceTechnology;
use App\Portfolio\Experience\Domain\Exception\ExperienceTechnologyAlreadyExistsException;
use App\Portfolio\Experience\Domain\Exception\ExperienceTechnologyNotFoundException;

interface ExperienceTechnologyAdministratorInterface
{
    /**
     * @throws ExperienceTechnologyNotFoundException si l'id est inconnu
     * @throws ExperienceTechnologyAlreadyExistsException si le nom est déjà utilisé par une autre technologie
     */
    public function update(int $id, string $name, float $years, ?string $iconKey, ?string $relatedTechnologyName): ExperienceTechnology;

    /**
     * @throws ExperienceTechnologyNotFoundException si l'id est inconnu
     */
    public function delete(int $id): void;
}
