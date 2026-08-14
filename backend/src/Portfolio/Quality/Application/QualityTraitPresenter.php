<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Application;

use App\Portfolio\Quality\Domain\Entity\QualityTrait as QualityTraitEntity;

final class QualityTraitPresenter implements QualityTraitPresenterInterface
{
    public function present(QualityTraitEntity $trait): array
    {
        return [
            'label' => $trait->getLabel(),
        ];
    }
}
