<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Application;

use App\Portfolio\Quality\Domain\Entity\QualityPrinciple;
use App\Portfolio\Quality\Domain\Exception\QualityPrincipleNotFoundException;
use App\Portfolio\Shared\Domain\ValueObject\Locale;

interface QualityPrincipleAdministratorInterface
{
    public function create(Locale $locale, string $title, string $description, string $iconKey, int $position): QualityPrinciple;

    /**
     * @throws QualityPrincipleNotFoundException si l'id est inconnu
     */
    public function update(int $id, string $title, string $description, string $iconKey, int $position): QualityPrinciple;

    /**
     * @throws QualityPrincipleNotFoundException si l'id est inconnu
     */
    public function delete(int $id): void;
}
