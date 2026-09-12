<?php

declare(strict_types=1);

namespace App\Portfolio\CaseStudy\Application;

use App\Portfolio\CaseStudy\Domain\Entity\CaseStudy;
use App\Portfolio\CaseStudy\Domain\Exception\CaseStudyNotFoundException;
use App\Portfolio\Shared\Domain\ValueObject\Locale;

interface CaseStudyAdministratorInterface
{
    public function create(
        Locale $locale,
        string $title,
        string $problem,
        string $solution,
        string $tradeoffs,
        string $measuredResult,
        int $position,
    ): CaseStudy;

    /**
     * @throws CaseStudyNotFoundException si l'id est inconnu
     */
    public function update(
        int $id,
        string $title,
        string $problem,
        string $solution,
        string $tradeoffs,
        string $measuredResult,
        int $position,
    ): CaseStudy;

    /**
     * @throws CaseStudyNotFoundException si l'id est inconnu
     */
    public function delete(int $id): void;
}
