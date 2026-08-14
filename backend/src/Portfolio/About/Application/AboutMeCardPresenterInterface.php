<?php

declare(strict_types=1);

namespace App\Portfolio\About\Application;

use App\Portfolio\About\Domain\Entity\AboutMeCard;

interface AboutMeCardPresenterInterface
{
    /**
     * @return array{title: string, description: string, iconKey: ?string}
     */
    public function present(AboutMeCard $card): array;
}
