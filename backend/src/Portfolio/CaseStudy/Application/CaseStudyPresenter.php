<?php

declare(strict_types=1);

namespace App\Portfolio\CaseStudy\Application;

use App\Portfolio\CaseStudy\Domain\Entity\CaseStudy;

final class CaseStudyPresenter implements CaseStudyPresenterInterface
{
    public function present(CaseStudy $caseStudy): array
    {
        return [
            'title' => $caseStudy->getTitle(),
            'problem' => $caseStudy->getProblem(),
            'solution' => $caseStudy->getSolution(),
            'tradeoffs' => $caseStudy->getTradeoffs(),
            'measuredResult' => $caseStudy->getMeasuredResult(),
        ];
    }
}
