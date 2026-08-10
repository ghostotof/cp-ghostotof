<?php

declare(strict_types=1);

namespace App\Portfolio\Experience\Application;

use App\Portfolio\Experience\Domain\Entity\ExperienceTechnology;
use App\Portfolio\Experience\Domain\Exception\ExperienceTechnologyAlreadyExistsException;

interface ExperienceTechnologyRegistrarInterface
{
    /**
     * @throws ExperienceTechnologyAlreadyExistsException si le nom est déjà utilisé
     */
    public function register(string $name, float $years, ?string $iconKey, ?string $relatedTechnologyName): ExperienceTechnology;
}
