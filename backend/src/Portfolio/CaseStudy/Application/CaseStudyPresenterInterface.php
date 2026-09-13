<?php

declare(strict_types=1);

namespace App\Portfolio\CaseStudy\Application;

use App\Portfolio\CaseStudy\Domain\Entity\CaseStudy;

interface CaseStudyPresenterInterface
{
    /**
     * @return array{title: string, problem: string, solution: string, tradeoffs: string, measuredResult: string}
     */
    public function present(CaseStudy $caseStudy): array;
}
