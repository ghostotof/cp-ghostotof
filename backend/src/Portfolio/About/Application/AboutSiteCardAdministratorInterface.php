<?php

declare(strict_types=1);

namespace App\Portfolio\About\Application;

use App\Portfolio\About\Domain\Entity\AboutSiteCard;
use App\Portfolio\About\Domain\Exception\AboutSiteCardNotFoundException;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Uid\Uuid;

interface AboutSiteCardAdministratorInterface
{
    public function create(Locale $locale, string $title, string $description, ?string $iconKey, int $position): AboutSiteCard;

    /**
     * @throws AboutSiteCardNotFoundException si l'id est inconnu
     */
    public function update(Uuid $id, string $title, string $description, ?string $iconKey, int $position): AboutSiteCard;

    /**
     * @throws AboutSiteCardNotFoundException si l'id est inconnu
     */
    public function delete(Uuid $id): void;
}
