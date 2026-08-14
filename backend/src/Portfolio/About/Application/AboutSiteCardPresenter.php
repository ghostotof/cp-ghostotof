<?php

declare(strict_types=1);

namespace App\Portfolio\About\Application;

use App\Portfolio\About\Domain\Entity\AboutSiteCard;

final class AboutSiteCardPresenter implements AboutSiteCardPresenterInterface
{
    public function present(AboutSiteCard $card): array
    {
        return [
            'title' => $card->getTitle(),
            'description' => $card->getDescription(),
            'iconKey' => $card->getIconKey(),
        ];
    }
}
