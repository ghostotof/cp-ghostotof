<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Application;

use App\Portfolio\Quality\Domain\Entity\QualityPrinciple;

interface QualityPrinciplePresenterInterface
{
    /**
     * @return array{title: string, description: string, iconKey: string}
     */
    public function present(QualityPrinciple $principle): array;
}
