<?php

declare(strict_types=1);

namespace App\Portfolio\Experience\Application;

use App\Portfolio\Experience\Domain\Entity\ExperienceTechnology;

final class ExperienceTechnologyPresenter implements ExperienceTechnologyPresenterInterface
{
    public function present(ExperienceTechnology $technology): array
    {
        $relatedTechnologyName = $technology->getRelatedTechnologyName();

        return [
            'name' => $technology->getName(),
            'years' => $technology->getYears(),
            'iconKey' => $technology->getIconKey(),
            'relatedTechnology' => null !== $relatedTechnologyName ? ['name' => $relatedTechnologyName] : null,
        ];
    }
}
