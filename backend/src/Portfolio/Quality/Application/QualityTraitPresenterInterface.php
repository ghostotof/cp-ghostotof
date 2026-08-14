<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Application;

use App\Portfolio\Quality\Domain\Entity\QualityTrait as QualityTraitEntity;

interface QualityTraitPresenterInterface
{
    /**
     * @return array{label: string}
     */
    public function present(QualityTraitEntity $trait): array;
}
