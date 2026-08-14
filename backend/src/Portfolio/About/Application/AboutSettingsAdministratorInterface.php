<?php

declare(strict_types=1);

namespace App\Portfolio\About\Application;

use App\Portfolio\About\Domain\Entity\AboutSettings;
use App\Portfolio\About\Domain\Exception\AboutSettingsNotFoundException;
use App\Portfolio\Shared\Domain\ValueObject\Locale;

interface AboutSettingsAdministratorInterface
{
    /**
     * @throws AboutSettingsNotFoundException si la locale n'a pas encore de réglages (pas de create() exposé :
     *                                        le seeding initial crée l'entité directement via le repository)
     */
    public function update(
        Locale $locale,
        string $siteEyebrow,
        string $meEyebrow,
        string $technicalSubtitle,
        string $personalSubtitle,
        string $hobbiesSubtitle,
    ): AboutSettings;
}
