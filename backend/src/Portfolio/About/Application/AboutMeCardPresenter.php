<?php

declare(strict_types=1);

namespace App\Portfolio\About\Application;

use App\Portfolio\About\Domain\Entity\AboutMeCard;

final class AboutMeCardPresenter implements AboutMeCardPresenterInterface
{
    public function present(AboutMeCard $card): array
    {
        return [
            'title' => $card->getTitle(),
            'description' => $card->getDescription(),
            'iconKey' => $card->getIconKey(),
        ];
    }
}
