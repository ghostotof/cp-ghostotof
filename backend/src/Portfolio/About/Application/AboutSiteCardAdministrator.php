<?php

declare(strict_types=1);

namespace App\Portfolio\About\Application;

use App\Portfolio\About\Domain\Entity\AboutSiteCard;
use App\Portfolio\About\Domain\Exception\AboutSiteCardNotFoundException;
use App\Portfolio\About\Domain\Repository\AboutSiteCardRepositoryInterface;
use App\Portfolio\Shared\Domain\Service\ContentPlacement;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Uid\Uuid;

final readonly class AboutSiteCardAdministrator implements AboutSiteCardAdministratorInterface
{
    public function __construct(
        private AboutSiteCardRepositoryInterface $aboutSiteCardRepository,
        private ContentPlacement $contentPlacement,
    ) {
    }

    public function create(
        Locale $locale,
        string $title,
        string $description,
        ?string $iconKey,
        ?Uuid $translationGroup = null,
    ): AboutSiteCard {
        $card = new AboutSiteCard($locale, $title, $description, $iconKey, $this->positionFor($locale, $translationGroup), $translationGroup);

        $this->aboutSiteCardRepository->save($card);

        return $card;
    }

    public function update(Uuid $id, string $title, string $description, ?string $iconKey, ?Uuid $translationGroup): AboutSiteCard
    {
        $card = $this->aboutSiteCardRepository->findOneById($id);

        if (null === $card) {
            throw AboutSiteCardNotFoundException::forId($id);
        }

        $this->contentPlacement->reattach(
            $card,
            $translationGroup,
            $this->aboutSiteCardRepository->findByTranslationGroup($translationGroup ?? $card->getTranslationGroup()),
        );
        $card->update($title, $description, $iconKey);
        $this->aboutSiteCardRepository->save($card);

        return $card;
    }

    public function delete(Uuid $id): void
    {
        $card = $this->aboutSiteCardRepository->findOneById($id);

        if (null === $card) {
            throw AboutSiteCardNotFoundException::forId($id);
        }

        $this->aboutSiteCardRepository->remove($card);
    }

    /**
     * Spec 0004 D3 : sans groupe, l'entrée se range en fin de périmètre
     * (toutes langues confondues) ; avec un groupe, elle hérite de sa position.
     * Le périmètre n'est chargé que dans la première branche.
     */
    private function positionFor(Locale $locale, ?Uuid $translationGroup): int
    {
        if (null === $translationGroup) {
            return $this->contentPlacement->atEndOf($this->aboutSiteCardRepository->findAll());
        }

        return $this->contentPlacement->inGroup(
            $translationGroup,
            $locale,
            $this->aboutSiteCardRepository->findByTranslationGroup($translationGroup),
        );
    }
}
