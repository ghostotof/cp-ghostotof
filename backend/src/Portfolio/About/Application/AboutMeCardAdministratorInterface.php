<?php

declare(strict_types=1);

namespace App\Portfolio\About\Application;

use App\Portfolio\About\Domain\Entity\AboutMeCard;
use App\Portfolio\About\Domain\Exception\AboutMeCardNotFoundException;
use App\Portfolio\About\Domain\ValueObject\AboutMeCardCategory;
use App\Portfolio\Shared\Domain\ValueObject\Locale;

interface AboutMeCardAdministratorInterface
{
    public function create(Locale $locale, AboutMeCardCategory $category, string $title, string $description, ?string $iconKey, int $position): AboutMeCard;

    /**
     * @throws AboutMeCardNotFoundException si l'id est inconnu
     */
    public function update(int $id, string $title, string $description, ?string $iconKey, int $position): AboutMeCard;

    /**
     * @throws AboutMeCardNotFoundException si l'id est inconnu
     */
    public function delete(int $id): void;
}
