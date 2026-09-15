<?php

declare(strict_types=1);

namespace App\Portfolio\CaseStudy\Application;

use App\Portfolio\CaseStudy\Domain\Entity\CaseStudy;
use App\Portfolio\CaseStudy\Domain\Exception\CaseStudyNotFoundException;
use App\Portfolio\CaseStudy\Domain\Repository\CaseStudyRepositoryInterface;
use App\Portfolio\Shared\Domain\Service\ContentPlacement;
use App\Portfolio\Shared\Domain\Service\OrderAssigner;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Uid\Uuid;

final readonly class CaseStudyAdministrator implements CaseStudyAdministratorInterface
{
    public function __construct(
        private CaseStudyRepositoryInterface $caseStudyRepository,
        private ContentPlacement $contentPlacement,
        private OrderAssigner $orderAssigner,
    ) {
    }

    public function create(
        Locale $locale,
        string $title,
        string $problem,
        string $solution,
        string $tradeoffs,
        string $measuredResult,
        ?Uuid $translationGroup = null,
    ): CaseStudy {
        $caseStudy = new CaseStudy($locale, $title, $problem, $solution, $tradeoffs, $measuredResult, $this->positionFor($locale, $translationGroup), $translationGroup);

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
        ?Uuid $translationGroup,
    ): CaseStudy {
        $caseStudy = $this->caseStudyRepository->findOneById($id);

        if (null === $caseStudy) {
            throw CaseStudyNotFoundException::forId($id);
        }

        if (null === $translationGroup) {
            // Issue #169 : détacher envoie l'entrée en fin de périmètre, d'où
            // le chargement du périmètre — le même qu'à la création sans groupe.
            $this->contentPlacement->detach(
                $caseStudy,
                $this->caseStudyRepository->findByTranslationGroup($caseStudy->getTranslationGroup()),
                $this->caseStudyRepository->findAll(),
            );
        } else {
            $this->contentPlacement->reattach(
                $caseStudy,
                $translationGroup,
                $this->caseStudyRepository->findByTranslationGroup($translationGroup),
            );
        }
        $caseStudy->update($title, $problem, $solution, $tradeoffs, $measuredResult);
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

    public function reorder(array $keys): void
    {
        $scope = $this->caseStudyRepository->findAll();

        $this->orderAssigner->assign($scope, $keys);

        $this->caseStudyRepository->saveAll($scope);
    }

    /**
     * Spec 0004 D3 : sans groupe, l'entrée se range en fin de périmètre
     * (toutes langues confondues) ; avec un groupe, elle hérite de sa position.
     * Le périmètre n'est chargé que dans la première branche.
     */
    private function positionFor(Locale $locale, ?Uuid $translationGroup): int
    {
        if (null === $translationGroup) {
            return $this->contentPlacement->atEndOf($this->caseStudyRepository->findAll());
        }

        return $this->contentPlacement->inGroup(
            $translationGroup,
            $locale,
            $this->caseStudyRepository->findByTranslationGroup($translationGroup),
        );
    }
}
