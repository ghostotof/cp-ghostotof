<?php

declare(strict_types=1);

namespace App\Portfolio\Contribution\Application;

use App\Portfolio\Contribution\Domain\Entity\Contribution;

final class ContributionPresenter implements ContributionPresenterInterface
{
    public function present(Contribution $contribution): array
    {
        return [
            'title' => $contribution->getTitle(),
            'project' => $contribution->getProject(),
            'reference' => $contribution->getReference(),
            'url' => $contribution->getUrl(),
            'summary' => $contribution->getSummary(),
            'body' => $contribution->getBody(),
        ];
    }
}
