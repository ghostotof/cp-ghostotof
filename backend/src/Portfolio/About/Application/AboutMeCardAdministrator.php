<?php

declare(strict_types=1);

namespace App\Portfolio\About\Application;

use App\Portfolio\About\Domain\Entity\AboutMeCard;
use App\Portfolio\About\Domain\Exception\AboutMeCardNotFoundException;
use App\Portfolio\About\Domain\Repository\AboutMeCardRepositoryInterface;
use App\Portfolio\About\Domain\ValueObject\AboutMeCardCategory;
use App\Portfolio\Shared\Domain\ValueObject\Locale;

final readonly class AboutMeCardAdministrator implements AboutMeCardAdministratorInterface
{
    public function __construct(
        private AboutMeCardRepositoryInterface $aboutMeCardRepository,
    ) {
    }

    public function create(Locale $locale, AboutMeCardCategory $category, string $title, string $description, ?string $iconKey, int $position): AboutMeCard
    {
        $card = new AboutMeCard($locale, $category, $title, $description, $iconKey, $position);

        $this->aboutMeCardRepository->save($card);

        return $card;
    }

    public function update(int $id, string $title, string $description, ?string $iconKey, int $position): AboutMeCard
    {
        $card = $this->aboutMeCardRepository->findOneById($id);

        if (null === $card) {
            throw AboutMeCardNotFoundException::forId($id);
        }

        $card->update($title, $description, $iconKey, $position);
        $this->aboutMeCardRepository->save($card);

        return $card;
    }

    public function delete(int $id): void
    {
        $card = $this->aboutMeCardRepository->findOneById($id);

        if (null === $card) {
            throw AboutMeCardNotFoundException::forId($id);
        }

        $this->aboutMeCardRepository->remove($card);
    }
}
