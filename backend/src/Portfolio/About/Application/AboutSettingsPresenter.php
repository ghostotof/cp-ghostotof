<?php

declare(strict_types=1);

namespace App\Portfolio\About\Application;

use App\Portfolio\About\Domain\Entity\AboutSettings;

final class AboutSettingsPresenter implements AboutSettingsPresenterInterface
{
    public function present(AboutSettings $settings): array
    {
        return [
            'siteEyebrow' => $settings->getSiteEyebrow(),
            'meEyebrow' => $settings->getMeEyebrow(),
            'technicalSubtitle' => $settings->getTechnicalSubtitle(),
            'personalSubtitle' => $settings->getPersonalSubtitle(),
            'hobbiesSubtitle' => $settings->getHobbiesSubtitle(),
        ];
    }
}
