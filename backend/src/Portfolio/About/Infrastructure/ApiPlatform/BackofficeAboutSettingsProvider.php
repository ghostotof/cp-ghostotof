<?php

declare(strict_types=1);

namespace App\Portfolio\About\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Portfolio\About\Domain\Entity\AboutSettings;
use App\Portfolio\About\Domain\Repository\AboutSettingsRepositoryInterface;
use App\Portfolio\About\Presentation\ApiResource\BackofficeAboutSettingsResource;
use App\Portfolio\Shared\Domain\ValueObject\Locale;

/**
 * @implements ProviderInterface<BackofficeAboutSettingsResource>
 */
final readonly class BackofficeAboutSettingsProvider implements ProviderInterface
{
    public function __construct(
        private AboutSettingsRepositoryInterface $aboutSettingsRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?BackofficeAboutSettingsResource
    {
        $locale = Locale::from((string) $uriVariables['locale']);
        $settings = $this->aboutSettingsRepository->findByLocale($locale);

        return null !== $settings ? $this->toResource($settings) : null;
    }

    private function toResource(AboutSettings $settings): BackofficeAboutSettingsResource
    {
        return new BackofficeAboutSettingsResource(
            locale: $settings->getLocale()->value,
            siteEyebrow: $settings->getSiteEyebrow(),
            meEyebrow: $settings->getMeEyebrow(),
            technicalSubtitle: $settings->getTechnicalSubtitle(),
            personalSubtitle: $settings->getPersonalSubtitle(),
            hobbiesSubtitle: $settings->getHobbiesSubtitle(),
        );
    }
}
