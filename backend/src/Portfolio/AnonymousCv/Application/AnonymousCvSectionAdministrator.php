<?php

declare(strict_types=1);

namespace App\Portfolio\AnonymousCv\Application;

use App\Portfolio\AnonymousCv\Domain\Entity\AnonymousCvSection;
use App\Portfolio\AnonymousCv\Domain\Exception\AnonymousCvSectionNotFoundException;
use App\Portfolio\AnonymousCv\Domain\Repository\AnonymousCvSectionRepositoryInterface;
use App\Portfolio\Shared\Domain\Service\ContentPlacement;
use App\Portfolio\Shared\Domain\Service\OrderAssigner;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Uid\Uuid;

final readonly class AnonymousCvSectionAdministrator implements AnonymousCvSectionAdministratorInterface
{
    public function __construct(
        private AnonymousCvSectionRepositoryInterface $sectionRepository,
        private ContentPlacement $contentPlacement,
        private OrderAssigner $orderAssigner,
    ) {
    }

    public function create(
        Locale $locale,
        string $title,
        string $skills,
        int $yearsOfExperience,
        string $achievements,
        ?Uuid $translationGroup = null,
    ): AnonymousCvSection {
        $section = new AnonymousCvSection($locale, $title, $skills, $yearsOfExperience, $achievements, $this->positionFor($locale, $translationGroup), $translationGroup);

        $this->sectionRepository->save($section);

        return $section;
    }

    public function update(
        Uuid $id,
        string $title,
        string $skills,
        int $yearsOfExperience,
        string $achievements,
        ?Uuid $translationGroup,
    ): AnonymousCvSection {
        $section = $this->sectionRepository->findOneById($id);

        if (null === $section) {
            throw AnonymousCvSectionNotFoundException::forId($id);
        }

        $this->contentPlacement->reattach(
            $section,
            $translationGroup,
            $this->sectionRepository->findByTranslationGroup($translationGroup ?? $section->getTranslationGroup()),
        );
        $section->update($title, $skills, $yearsOfExperience, $achievements);
        $this->sectionRepository->save($section);

        return $section;
    }

    public function delete(Uuid $id): void
    {
        $section = $this->sectionRepository->findOneById($id);

        if (null === $section) {
            throw AnonymousCvSectionNotFoundException::forId($id);
        }

        $this->sectionRepository->remove($section);
    }

    public function reorder(array $keys): void
    {
        $scope = $this->sectionRepository->findAll();

        $this->orderAssigner->assign($scope, $keys);

        $this->sectionRepository->saveAll($scope);
    }

    /**
     * Spec 0004 D3 : sans groupe, l'entrée se range en fin de périmètre
     * (toutes langues confondues) ; avec un groupe, elle hérite de sa position.
     * Le périmètre n'est chargé que dans la première branche.
     */
    private function positionFor(Locale $locale, ?Uuid $translationGroup): int
    {
        if (null === $translationGroup) {
            return $this->contentPlacement->atEndOf($this->sectionRepository->findAll());
        }

        return $this->contentPlacement->inGroup(
            $translationGroup,
            $locale,
            $this->sectionRepository->findByTranslationGroup($translationGroup),
        );
    }
}
