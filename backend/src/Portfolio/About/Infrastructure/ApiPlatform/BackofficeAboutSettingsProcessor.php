<?php

declare(strict_types=1);

namespace App\Portfolio\About\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Portfolio\About\Application\AboutSettingsAdministratorInterface;
use App\Portfolio\About\Domain\Entity\AboutSettings;
use App\Portfolio\About\Presentation\ApiResource\BackofficeAboutSettingsResource;
use App\Portfolio\Shared\Domain\ValueObject\Locale;

/**
 * @implements ProcessorInterface<BackofficeAboutSettingsResource, BackofficeAboutSettingsResource>
 */
final readonly class BackofficeAboutSettingsProcessor implements ProcessorInterface
{
    public function __construct(
        private AboutSettingsAdministratorInterface $aboutSettingsAdministrator,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): BackofficeAboutSettingsResource
    {
        \assert($data instanceof BackofficeAboutSettingsResource);

        $settings = $this->aboutSettingsAdministrator->update(
            Locale::from((string) $uriVariables['locale']),
            $data->siteEyebrow,
            $data->meEyebrow,
            $data->technicalSubtitle,
            $data->personalSubtitle,
            $data->hobbiesSubtitle,
        );

        return $this->toResource($settings);
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
