<?php

declare(strict_types=1);

namespace App\Portfolio\Contribution\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Portfolio\Contribution\Application\ContributionPresenterInterface;
use App\Portfolio\Contribution\Domain\Entity\Contribution;
use App\Portfolio\Contribution\Domain\Repository\ContributionRepositoryInterface;
use App\Portfolio\Contribution\Presentation\ApiResource\ContributionResource;
use App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables;

/**
 * Relie ContributionResource (Presentation) au Domain : seule cette classe
 * Infrastructure a le droit de connaître à la fois l'entité Doctrine (via le
 * repository) et la ressource API Platform.
 *
 * @implements ProviderInterface<ContributionResource>
 */
final readonly class ContributionProvider implements ProviderInterface
{
    use ResolvesUriVariables;

    public function __construct(
        private ContributionRepositoryInterface $contributionRepository,
        private ContributionPresenterInterface $contributionPresenter,
    ) {
    }

    /**
     * @return list<ContributionResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $locale = $this->uriVariableLocale($uriVariables);

        return array_map(
            fn (Contribution $contribution): ContributionResource => new ContributionResource(
                ...$this->contributionPresenter->present($contribution),
            ),
            $this->contributionRepository->findByLocale($locale),
        );
    }
}
