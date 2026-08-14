<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Application;

use App\Portfolio\Quality\Domain\Entity\QualityPrinciple;

final class QualityPrinciplePresenter implements QualityPrinciplePresenterInterface
{
    public function present(QualityPrinciple $principle): array
    {
        return [
            'title' => $principle->getTitle(),
            'description' => $principle->getDescription(),
            'iconKey' => $principle->getIconKey(),
        ];
    }
}
