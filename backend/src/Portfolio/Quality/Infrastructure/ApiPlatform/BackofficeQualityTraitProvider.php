<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Portfolio\Quality\Domain\Repository\QualityTraitRepositoryInterface;
use App\Portfolio\Quality\Presentation\ApiResource\BackofficeQualityTraitResource;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables;
use Symfony\Component\HttpFoundation\Request;

/**
 * @implements ProviderInterface<BackofficeQualityTraitResource>
 */
final readonly class BackofficeQualityTraitProvider implements ProviderInterface
{
    use ResolvesUriVariables;

    public function __construct(
        private QualityTraitRepositoryInterface $qualityTraitRepository,
    ) {
    }

    /**
     * @return BackofficeQualityTraitResource|list<BackofficeQualityTraitResource>|null
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): BackofficeQualityTraitResource|array|null
    {
        if ($operation instanceof GetCollection) {
            $request = $context['request'] ?? null;
            \assert($request instanceof Request);

            $locale = Locale::tryFrom($request->query->get('locale', ''));

            $traits = null !== $locale
                ? $this->qualityTraitRepository->findByLocale($locale)
                : $this->qualityTraitRepository->findAll();

            return array_map(BackofficeQualityTraitResource::fromEntity(...), $traits);
        }

        $trait = $this->qualityTraitRepository->findOneById($this->uriVariableInt($uriVariables, 'id'));

        return null !== $trait ? BackofficeQualityTraitResource::fromEntity($trait) : null;
    }
}
