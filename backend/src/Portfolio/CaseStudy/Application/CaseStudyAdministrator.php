<?php

declare(strict_types=1);

namespace App\Portfolio\CaseStudy\Application;

use App\Portfolio\CaseStudy\Domain\Entity\CaseStudy;
use App\Portfolio\CaseStudy\Domain\Exception\CaseStudyNotFoundException;
use App\Portfolio\CaseStudy\Domain\Repository\CaseStudyRepositoryInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Uid\Uuid;

final readonly class CaseStudyAdministrator implements CaseStudyAdministratorInterface
{
    public function __construct(
        private CaseStudyRepositoryInterface $caseStudyRepository,
    ) {
    }

    public function create(
        Locale $locale,
        string $title,
        string $problem,
        string $solution,
        string $tradeoffs,
        string $measuredResult,
        int $position,
        ?Uuid $translationGroup = null,
    ): CaseStudy {
        $caseStudy = new CaseStudy($locale, $title, $problem, $solution, $tradeoffs, $measuredResult, $position, $translationGroup);

        $this->caseStudyRepository->save($caseStudy);

        return $caseStudy;
    }

    public function update(
        Uuid $id,
        string $title,
        string $problem,
        string $solution,
        string $tradeoffs,
        string $measuredResult,
        int $position,
    ): CaseStudy {
        $caseStudy = $this->caseStudyRepository->findOneById($id);

        if (null === $caseStudy) {
            throw CaseStudyNotFoundException::forId($id);
        }

        $caseStudy->update($title, $problem, $solution, $tradeoffs, $measuredResult, $position);
        $this->caseStudyRepository->save($caseStudy);

        return $caseStudy;
    }

    public function delete(Uuid $id): void
    {
        $caseStudy = $this->caseStudyRepository->findOneById($id);

        if (null === $caseStudy) {
            throw CaseStudyNotFoundException::forId($id);
        }

        $this->caseStudyRepository->remove($caseStudy);
    }
}
