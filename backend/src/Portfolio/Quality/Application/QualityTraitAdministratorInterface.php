<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Application;

use App\Portfolio\Quality\Domain\Entity\QualityTrait as QualityTraitEntity;
use App\Portfolio\Quality\Domain\Exception\QualityTraitNotFoundException;
use App\Portfolio\Shared\Domain\ValueObject\Locale;

interface QualityTraitAdministratorInterface
{
    public function create(Locale $locale, string $label, int $position): QualityTraitEntity;

    /**
     * @throws QualityTraitNotFoundException si l'id est inconnu
     */
    public function update(int $id, string $label, int $position): QualityTraitEntity;

    /**
     * @throws QualityTraitNotFoundException si l'id est inconnu
     */
    public function delete(int $id): void;
}
