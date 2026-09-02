<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Portfolio\Quality\Domain\Entity\QualityPrinciple;
use App\Portfolio\Quality\Domain\Repository\QualityPrincipleRepositoryInterface;
use App\Portfolio\Quality\Presentation\ApiResource\BackofficeQualityPrincipleResource;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables;
use Symfony\Component\HttpFoundation\Request;

/**
 * @implements ProviderInterface<BackofficeQualityPrincipleResource>
 */
final readonly class BackofficeQualityPrincipleProvider implements ProviderInterface
{
    use ResolvesUriVariables;

    public function __construct(
        private QualityPrincipleRepositoryInterface $qualityPrincipleRepository,
    ) {
    }

    /**
     * @return BackofficeQualityPrincipleResource|list<BackofficeQualityPrincipleResource>|null
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): BackofficeQualityPrincipleResource|array|null
    {
        if ($operation instanceof GetCollection) {
            $request = $context['request'] ?? null;
            \assert($request instanceof Request);

            $locale = Locale::tryFrom($request->query->get('locale', ''));

            $principles = null !== $locale
                ? $this->qualityPrincipleRepository->findByLocale($locale)
                : $this->qualityPrincipleRepository->findAll();

            return array_map($this->toResource(...), $principles);
        }

        $principle = $this->qualityPrincipleRepository->findOneById($this->uriVariableInt($uriVariables, 'id'));

        return null !== $principle ? $this->toResource($principle) : null;
    }

    private function toResource(QualityPrinciple $principle): BackofficeQualityPrincipleResource
    {
        return new BackofficeQualityPrincipleResource(
            id: $principle->getId(),
            locale: $principle->getLocale()->value,
            title: $principle->getTitle(),
            description: $principle->getDescription(),
            iconKey: $principle->getIconKey(),
            position: $principle->getPosition(),
        );
    }
}
