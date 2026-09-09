<?php

declare(strict_types=1);

namespace App\Portfolio\About\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Portfolio\About\Domain\Repository\AboutMeCardRepositoryInterface;
use App\Portfolio\About\Domain\ValueObject\AboutMeCardCategory;
use App\Portfolio\About\Presentation\ApiResource\BackofficeAboutMeCardResource;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables;
use Symfony\Component\HttpFoundation\Request;

/**
 * @implements ProviderInterface<BackofficeAboutMeCardResource>
 */
final readonly class BackofficeAboutMeCardProvider implements ProviderInterface
{
    use ResolvesUriVariables;

    public function __construct(
        private AboutMeCardRepositoryInterface $aboutMeCardRepository,
    ) {
    }

    /**
     * @return BackofficeAboutMeCardResource|list<BackofficeAboutMeCardResource>|null
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): BackofficeAboutMeCardResource|array|null
    {
        if ($operation instanceof GetCollection) {
            $request = $context['request'] ?? null;
            \assert($request instanceof Request);

            $locale = Locale::tryFrom($request->query->get('locale', ''));
            $category = AboutMeCardCategory::tryFrom($request->query->get('category', ''));

            $cards = match (true) {
                null !== $locale && null !== $category => $this->aboutMeCardRepository->findByLocaleAndCategory($locale, $category),
                null !== $locale => $this->aboutMeCardRepository->findByLocale($locale),
                null !== $category => $this->aboutMeCardRepository->findByCategory($category),
                default => $this->aboutMeCardRepository->findAll(),
            };

            return array_map(BackofficeAboutMeCardResource::fromEntity(...), $cards);
        }

        $card = $this->aboutMeCardRepository->findOneById($this->uriVariableInt($uriVariables, 'id'));

        return null !== $card ? BackofficeAboutMeCardResource::fromEntity($card) : null;
    }
}
