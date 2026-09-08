<?php

declare(strict_types=1);

namespace App\Portfolio\About\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Portfolio\About\Application\AboutSettingsAdministratorInterface;
use App\Portfolio\About\Presentation\ApiResource\BackofficeAboutSettingsResource;
use App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables;

/**
 * @implements ProcessorInterface<BackofficeAboutSettingsResource, BackofficeAboutSettingsResource>
 */
final readonly class BackofficeAboutSettingsProcessor implements ProcessorInterface
{
    use ResolvesUriVariables;

    public function __construct(
        private AboutSettingsAdministratorInterface $aboutSettingsAdministrator,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): BackofficeAboutSettingsResource
    {
        $settings = $this->aboutSettingsAdministrator->update(
            $this->uriVariableLocale($uriVariables),
            $data->siteEyebrow,
            $data->meEyebrow,
            $data->technicalSubtitle,
            $data->personalSubtitle,
            $data->hobbiesSubtitle,
        );

        return BackofficeAboutSettingsResource::fromEntity($settings);
    }
}
