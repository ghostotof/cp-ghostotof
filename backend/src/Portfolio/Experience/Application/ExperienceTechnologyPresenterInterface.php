<?php

declare(strict_types=1);

namespace App\Portfolio\Experience\Application;

use App\Portfolio\Experience\Domain\Entity\ExperienceTechnology;

interface ExperienceTechnologyPresenterInterface
{
    /**
     * @return array{name: string, years: float, iconKey: ?string, relatedTechnology: ?array{name: string}, secondary: bool}
     */
    public function present(ExperienceTechnology $technology): array;
}
