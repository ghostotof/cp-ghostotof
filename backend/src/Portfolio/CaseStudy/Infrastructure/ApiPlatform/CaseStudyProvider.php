<?php

declare(strict_types=1);

namespace App\Portfolio\CaseStudy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Portfolio\CaseStudy\Application\CaseStudyPresenterInterface;
use App\Portfolio\CaseStudy\Domain\Entity\CaseStudy;
use App\Portfolio\CaseStudy\Domain\Repository\CaseStudyRepositoryInterface;
use App\Portfolio\CaseStudy\Presentation\ApiResource\CaseStudyResource;
use App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables;

/**
 * @implements ProviderInterface<CaseStudyResource>
 */
final readonly class CaseStudyProvider implements ProviderInterface
{
    use ResolvesUriVariables;

    public function __construct(
        private CaseStudyRepositoryInterface $caseStudyRepository,
        private CaseStudyPresenterInterface $caseStudyPresenter,
    ) {
    }

    /**
     * @return list<CaseStudyResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $locale = $this->uriVariableLocale($uriVariables);

        return array_map(
            fn (CaseStudy $caseStudy): CaseStudyResource => new CaseStudyResource(
                ...$this->caseStudyPresenter->present($caseStudy),
            ),
            $this->caseStudyRepository->findByLocale($locale),
        );
    }
}
