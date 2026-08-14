<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Portfolio\Quality\Application\QualityPrinciplePresenterInterface;
use App\Portfolio\Quality\Application\QualityTraitPresenterInterface;
use App\Portfolio\Quality\Domain\Repository\QualityPrincipleRepositoryInterface;
use App\Portfolio\Quality\Domain\Repository\QualityTraitRepositoryInterface;
use App\Portfolio\Quality\Presentation\ApiResource\QualityContentResource;
use App\Portfolio\Quality\Presentation\ApiResource\QualityPrincipleResource;
use App\Portfolio\Quality\Presentation\ApiResource\QualityTraitResource;
use App\Portfolio\Shared\Domain\ValueObject\Locale;

/**
 * Relie QualityContentResource (Presentation) au Domain, en agrégeant les
 * deux repositories (principles + traits) : seule cette classe
 * Infrastructure a le droit de connaître à la fois les entités Doctrine et
 * la ressource API Platform. Même pattern que
 * App\Portfolio\Stats\Infrastructure\ApiPlatform\StatProvider.
 *
 * @implements ProviderInterface<QualityContentResource>
 */
final readonly class QualityContentProvider implements ProviderInterface
{
    public function __construct(
        private QualityPrincipleRepositoryInterface $qualityPrincipleRepository,
        private QualityPrinciplePresenterInterface $qualityPrinciplePresenter,
        private QualityTraitRepositoryInterface $qualityTraitRepository,
        private QualityTraitPresenterInterface $qualityTraitPresenter,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): QualityContentResource
    {
        $locale = Locale::from((string) $uriVariables['locale']);

        return new QualityContentResource(
            principles: array_map(
                fn ($principle): QualityPrincipleResource => new QualityPrincipleResource(...$this->qualityPrinciplePresenter->present($principle)),
                $this->qualityPrincipleRepository->findByLocale($locale),
            ),
            traits: array_map(
                fn ($trait): QualityTraitResource => new QualityTraitResource(...$this->qualityTraitPresenter->present($trait)),
                $this->qualityTraitRepository->findByLocale($locale),
            ),
        );
    }
}
