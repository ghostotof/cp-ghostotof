<?php

declare(strict_types=1);

namespace App\Portfolio\About\Application;

use App\Portfolio\About\Domain\Entity\AboutSiteCard;
use App\Portfolio\About\Domain\Exception\AboutSiteCardNotFoundException;
use App\Portfolio\Shared\Domain\ValueObject\Locale;

interface AboutSiteCardAdministratorInterface
{
    public function create(Locale $locale, string $title, string $description, ?string $iconKey, int $position): AboutSiteCard;

    /**
     * @throws AboutSiteCardNotFoundException si l'id est inconnu
     */
    public function update(int $id, string $title, string $description, ?string $iconKey, int $position): AboutSiteCard;

    /**
     * @throws AboutSiteCardNotFoundException si l'id est inconnu
     */
    public function delete(int $id): void;
}
