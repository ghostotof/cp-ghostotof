<?php

declare(strict_types=1);

namespace App\Portfolio\About\Application;

use App\Portfolio\About\Domain\Entity\AboutSettings;
use App\Portfolio\About\Domain\Exception\AboutSettingsNotFoundException;
use App\Portfolio\About\Domain\Repository\AboutSettingsRepositoryInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;

final readonly class AboutSettingsAdministrator implements AboutSettingsAdministratorInterface
{
    public function __construct(
        private AboutSettingsRepositoryInterface $aboutSettingsRepository,
    ) {
    }

    public function update(
        Locale $locale,
        string $siteEyebrow,
        string $meEyebrow,
        string $technicalSubtitle,
        string $personalSubtitle,
        string $hobbiesSubtitle,
    ): AboutSettings {
        $settings = $this->aboutSettingsRepository->findByLocale($locale);

        if (null === $settings) {
            throw AboutSettingsNotFoundException::forLocale($locale);
        }

        $settings->update($siteEyebrow, $meEyebrow, $technicalSubtitle, $personalSubtitle, $hobbiesSubtitle);
        $this->aboutSettingsRepository->save($settings);

        return $settings;
    }
}
