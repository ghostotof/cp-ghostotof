<?php

declare(strict_types=1);

namespace App\Portfolio\Experience\Presentation\ApiResource;

final readonly class ExperienceRelatedTechnologyResource
{
    public function __construct(
        public string $name,
    ) {
    }
}
