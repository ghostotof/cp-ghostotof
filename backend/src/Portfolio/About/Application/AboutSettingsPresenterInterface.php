<?php

declare(strict_types=1);

namespace App\Portfolio\About\Application;

use App\Portfolio\About\Domain\Entity\AboutSettings;

interface AboutSettingsPresenterInterface
{
    /**
     * @return array{siteEyebrow: string, meEyebrow: string, technicalSubtitle: string, personalSubtitle: string, hobbiesSubtitle: string}
     */
    public function present(AboutSettings $settings): array;
}
