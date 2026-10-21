<?php

declare(strict_types=1);

namespace App\Portfolio\About\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Portfolio\About\Domain\Exception\AboutSettingsNotFoundException;
use App\Portfolio\About\Domain\Repository\AboutSettingsRepositoryInterface;
use App\Portfolio\About\Presentation\ApiResource\BackofficeAboutSettingsResource;
use App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables;

/**
 * Des réglages absents pour une locale valide sont un défaut de données (seed
 * absent, ligne supprimée) : {@see AboutSettingsNotFoundException}, une 404 en
 * `warning`, comme sur la route publique — jamais `null`, dont API Platform fait
 * une 404 générique, journalisée en `info` depuis #678.
 *
 * @implements ProviderInterface<BackofficeAboutSettingsResource>
 */
final readonly class BackofficeAboutSettingsProvider implements ProviderInterface
{
    use ResolvesUriVariables;

    public function __construct(
        private AboutSettingsRepositoryInterface $aboutSettingsRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): BackofficeAboutSettingsResource
    {
        $locale = $this->uriVariableLocale($uriVariables);
        $settings = $this->aboutSettingsRepository->findByLocale($locale)
            ?? throw AboutSettingsNotFoundException::forLocale($locale);

        return BackofficeAboutSettingsResource::fromEntity($settings);
    }
}
