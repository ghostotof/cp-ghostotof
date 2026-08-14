<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Portfolio\Quality\Domain\Entity\QualityTrait as QualityTraitEntity;
use App\Portfolio\Quality\Domain\Repository\QualityTraitRepositoryInterface;
use App\Portfolio\Quality\Presentation\ApiResource\BackofficeQualityTraitResource;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\HttpFoundation\Request;

/**
 * @implements ProviderInterface<BackofficeQualityTraitResource>
 */
final readonly class BackofficeQualityTraitProvider implements ProviderInterface
{
    public function __construct(
        private QualityTraitRepositoryInterface $qualityTraitRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): BackofficeQualityTraitResource|array|null
    {
        if ($operation instanceof GetCollection) {
            $request = $context['request'] ?? null;
            \assert($request instanceof Request);

            $locale = Locale::tryFrom((string) $request->query->get('locale', ''));

            $traits = null !== $locale
                ? $this->qualityTraitRepository->findByLocale($locale)
                : $this->qualityTraitRepository->findAll();

            return array_map($this->toResource(...), $traits);
        }

        $trait = $this->qualityTraitRepository->findOneById((int) $uriVariables['id']);

        return null !== $trait ? $this->toResource($trait) : null;
    }

    private function toResource(QualityTraitEntity $trait): BackofficeQualityTraitResource
    {
        return new BackofficeQualityTraitResource(
            id: $trait->getId(),
            locale: $trait->getLocale()->value,
            label: $trait->getLabel(),
            position: $trait->getPosition(),
        );
    }
}
