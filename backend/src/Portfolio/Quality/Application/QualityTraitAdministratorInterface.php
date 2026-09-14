<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Application;

use App\Portfolio\Quality\Domain\Entity\QualityTrait as QualityTraitEntity;
use App\Portfolio\Quality\Domain\Exception\QualityTraitNotFoundException;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Uid\Uuid;

interface QualityTraitAdministratorInterface
{
    public function create(Locale $locale, string $label, int $position): QualityTraitEntity;

    /**
     * @throws QualityTraitNotFoundException si l'id est inconnu
     */
    public function update(Uuid $id, string $label, int $position): QualityTraitEntity;

    /**
     * @throws QualityTraitNotFoundException si l'id est inconnu
     */
    public function delete(Uuid $id): void;
}
