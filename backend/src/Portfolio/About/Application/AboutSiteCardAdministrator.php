<?php

declare(strict_types=1);

namespace App\Portfolio\About\Application;

use App\Portfolio\About\Domain\Entity\AboutSiteCard;
use App\Portfolio\About\Domain\Exception\AboutSiteCardNotFoundException;
use App\Portfolio\About\Domain\Repository\AboutSiteCardRepositoryInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Uid\Uuid;

final readonly class AboutSiteCardAdministrator implements AboutSiteCardAdministratorInterface
{
    public function __construct(
        private AboutSiteCardRepositoryInterface $aboutSiteCardRepository,
    ) {
    }

    public function create(
        Locale $locale,
        string $title,
        string $description,
        ?string $iconKey,
        int $position,
        ?Uuid $translationGroup = null,
    ): AboutSiteCard {
        $card = new AboutSiteCard($locale, $title, $description, $iconKey, $position, $translationGroup);

        $this->aboutSiteCardRepository->save($card);

        return $card;
    }

    public function update(Uuid $id, string $title, string $description, ?string $iconKey, int $position): AboutSiteCard
    {
        $card = $this->aboutSiteCardRepository->findOneById($id);

        if (null === $card) {
            throw AboutSiteCardNotFoundException::forId($id);
        }

        $card->update($title, $description, $iconKey, $position);
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
}
