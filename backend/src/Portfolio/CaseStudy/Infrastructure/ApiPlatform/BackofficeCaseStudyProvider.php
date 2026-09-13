<?php

declare(strict_types=1);

namespace App\Portfolio\CaseStudy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Portfolio\CaseStudy\Domain\Repository\CaseStudyRepositoryInterface;
use App\Portfolio\CaseStudy\Presentation\ApiResource\BackofficeCaseStudyResource;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables;
use Symfony\Component\HttpFoundation\Request;

/**
 * @implements ProviderInterface<BackofficeCaseStudyResource>
 */
final readonly class BackofficeCaseStudyProvider implements ProviderInterface
{
    use ResolvesUriVariables;

    public function __construct(
        private CaseStudyRepositoryInterface $caseStudyRepository,
    ) {
    }

    /**
     * @return BackofficeCaseStudyResource|list<BackofficeCaseStudyResource>|null
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): BackofficeCaseStudyResource|array|null
    {
        if ($operation instanceof GetCollection) {
            $request = $context['request'] ?? null;
            \assert($request instanceof Request);

            // Locale::tryFrom (et non fromString) : un filtre de query string
            // absent ou fantaisiste ne doit pas produire un 404, seulement
            // retomber sur la collection complète.
            $locale = Locale::tryFrom($request->query->get('locale', ''));

            $caseStudies = null !== $locale
                ? $this->caseStudyRepository->findByLocale($locale)
                : $this->caseStudyRepository->findAll();

            return array_map(BackofficeCaseStudyResource::fromEntity(...), $caseStudies);
        }

        $caseStudy = $this->caseStudyRepository->findOneById($this->uriVariableInt($uriVariables, 'id'));

        return null !== $caseStudy ? BackofficeCaseStudyResource::fromEntity($caseStudy) : null;
    }
}
