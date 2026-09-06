<?php

declare(strict_types=1);

namespace App\Portfolio\Contribution\Application;

use App\Portfolio\Contribution\Domain\Entity\Contribution;

interface ContributionPresenterInterface
{
    /**
     * @return array{title: string, project: string, reference: string, url: string, summary: string, body: string}
     */
    public function present(Contribution $contribution): array;
}
