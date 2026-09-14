<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Application;

use App\Portfolio\Quality\Domain\Entity\QualityTrait as QualityTraitEntity;
use App\Portfolio\Quality\Domain\Exception\QualityTraitNotFoundException;
use App\Portfolio\Quality\Domain\Repository\QualityTraitRepositoryInterface;
use App\Portfolio\Shared\Domain\Service\ContentPlacement;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Uid\Uuid;

final readonly class QualityTraitAdministrator implements QualityTraitAdministratorInterface
{
    public function __construct(
        private QualityTraitRepositoryInterface $qualityTraitRepository,
        private ContentPlacement $contentPlacement,
    ) {
    }

    public function create(
        Locale $locale,
        string $label,
        ?Uuid $translationGroup = null,
    ): QualityTraitEntity {
        $trait = new QualityTraitEntity($locale, $label, $this->positionFor($locale, $translationGroup), $translationGroup);

        $this->qualityTraitRepository->save($trait);

        return $trait;
    }

    public function update(Uuid $id, string $label, ?Uuid $translationGroup): QualityTraitEntity
    {
        $trait = $this->qualityTraitRepository->findOneById($id);

        if (null === $trait) {
            throw QualityTraitNotFoundException::forId($id);
        }

        $this->contentPlacement->reattach(
            $trait,
            $translationGroup,
            $this->qualityTraitRepository->findByTranslationGroup($translationGroup ?? $trait->getTranslationGroup()),
        );
        $trait->update($label);
        $this->qualityTraitRepository->save($trait);

        return $trait;
    }

    public function delete(Uuid $id): void
    {
        $trait = $this->qualityTraitRepository->findOneById($id);

        if (null === $trait) {
            throw QualityTraitNotFoundException::forId($id);
        }

        $this->qualityTraitRepository->remove($trait);
    }

    /**
     * Spec 0004 D3 : sans groupe, l'entrée se range en fin de périmètre
     * (toutes langues confondues) ; avec un groupe, elle hérite de sa position.
     * Le périmètre n'est chargé que dans la première branche.
     */
    private function positionFor(Locale $locale, ?Uuid $translationGroup): int
    {
        if (null === $translationGroup) {
            return $this->contentPlacement->atEndOf($this->qualityTraitRepository->findAll());
        }

        return $this->contentPlacement->inGroup(
            $translationGroup,
            $locale,
            $this->qualityTraitRepository->findByTranslationGroup($translationGroup),
        );
    }
}
