<?php

declare(strict_types=1);

namespace App\Portfolio\About\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Portfolio\About\Domain\Repository\AboutSettingsRepositoryInterface;
use App\Portfolio\About\Presentation\ApiResource\BackofficeAboutSettingsResource;
use App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables;

/**
 * @implements ProviderInterface<BackofficeAboutSettingsResource>
 */
final readonly class BackofficeAboutSettingsProvider implements ProviderInterface
{
    use ResolvesUriVariables;

    public function __construct(
        private AboutSettingsRepositoryInterface $aboutSettingsRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?BackofficeAboutSettingsResource
    {
        $locale = $this->uriVariableLocale($uriVariables);
        $settings = $this->aboutSettingsRepository->findByLocale($locale);

        return null !== $settings ? BackofficeAboutSettingsResource::fromEntity($settings) : null;
    }
}
