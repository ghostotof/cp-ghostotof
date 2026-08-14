<?php

declare(strict_types=1);

namespace App\Portfolio\About\Application;

use App\Portfolio\About\Domain\Entity\AboutSiteCard;

interface AboutSiteCardPresenterInterface
{
    /**
     * @return array{title: string, description: string, iconKey: ?string}
     */
    public function present(AboutSiteCard $card): array;
}
