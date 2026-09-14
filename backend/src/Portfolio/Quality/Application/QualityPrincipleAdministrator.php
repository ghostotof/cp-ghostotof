<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Application;

use App\Portfolio\Quality\Domain\Entity\QualityPrinciple;
use App\Portfolio\Quality\Domain\Exception\QualityPrincipleNotFoundException;
use App\Portfolio\Quality\Domain\Repository\QualityPrincipleRepositoryInterface;
use App\Portfolio\Shared\Domain\Service\ContentPlacement;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Uid\Uuid;

final readonly class QualityPrincipleAdministrator implements QualityPrincipleAdministratorInterface
{
    public function __construct(
        private QualityPrincipleRepositoryInterface $qualityPrincipleRepository,
        private ContentPlacement $contentPlacement,
    ) {
    }

    public function create(
        Locale $locale,
        string $title,
        string $description,
        string $iconKey,
        ?Uuid $translationGroup = null,
    ): QualityPrinciple {
        $principle = new QualityPrinciple($locale, $title, $description, $iconKey, $this->positionFor($locale, $translationGroup), $translationGroup);

        $this->qualityPrincipleRepository->save($principle);

        return $principle;
    }

    public function update(Uuid $id, string $title, string $description, string $iconKey, ?Uuid $translationGroup): QualityPrinciple
    {
        $principle = $this->qualityPrincipleRepository->findOneById($id);

        if (null === $principle) {
            throw QualityPrincipleNotFoundException::forId($id);
        }

        $this->contentPlacement->reattach(
            $principle,
            $translationGroup,
            $this->qualityPrincipleRepository->findByTranslationGroup($translationGroup ?? $principle->getTranslationGroup()),
        );
        $principle->update($title, $description, $iconKey);
        $this->qualityPrincipleRepository->save($principle);

        return $principle;
    }

    public function delete(Uuid $id): void
    {
        $principle = $this->qualityPrincipleRepository->findOneById($id);

        if (null === $principle) {
            throw QualityPrincipleNotFoundException::forId($id);
        }

        $this->qualityPrincipleRepository->remove($principle);
    }

    /**
     * Spec 0004 D3 : sans groupe, l'entrée se range en fin de périmètre
     * (toutes langues confondues) ; avec un groupe, elle hérite de sa position.
     * Le périmètre n'est chargé que dans la première branche.
     */
    private function positionFor(Locale $locale, ?Uuid $translationGroup): int
    {
        if (null === $translationGroup) {
            return $this->contentPlacement->atEndOf($this->qualityPrincipleRepository->findAll());
        }

        return $this->contentPlacement->inGroup(
            $translationGroup,
            $locale,
            $this->qualityPrincipleRepository->findByTranslationGroup($translationGroup),
        );
    }
}
