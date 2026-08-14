<?php

declare(strict_types=1);

namespace App\Portfolio\About\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Portfolio\About\Domain\Entity\AboutMeCard;
use App\Portfolio\About\Domain\Repository\AboutMeCardRepositoryInterface;
use App\Portfolio\About\Domain\ValueObject\AboutMeCardCategory;
use App\Portfolio\About\Presentation\ApiResource\BackofficeAboutMeCardResource;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\HttpFoundation\Request;

/**
 * @implements ProviderInterface<BackofficeAboutMeCardResource>
 */
final readonly class BackofficeAboutMeCardProvider implements ProviderInterface
{
    public function __construct(
        private AboutMeCardRepositoryInterface $aboutMeCardRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): BackofficeAboutMeCardResource|array|null
    {
        if ($operation instanceof GetCollection) {
            $request = $context['request'] ?? null;
            \assert($request instanceof Request);

            $locale = Locale::tryFrom((string) $request->query->get('locale', ''));
            $category = AboutMeCardCategory::tryFrom((string) $request->query->get('category', ''));

            $cards = match (true) {
                null !== $locale && null !== $category => $this->aboutMeCardRepository->findByLocaleAndCategory($locale, $category),
                null !== $locale => $this->aboutMeCardRepository->findByLocale($locale),
                null !== $category => array_values(array_filter(
                    $this->aboutMeCardRepository->findAll(),
                    static fn (AboutMeCard $card): bool => $category === $card->getCategory(),
                )),
                default => $this->aboutMeCardRepository->findAll(),
            };

            return array_map($this->toResource(...), $cards);
        }

        $card = $this->aboutMeCardRepository->findOneById((int) $uriVariables['id']);

        return null !== $card ? $this->toResource($card) : null;
    }

    private function toResource(AboutMeCard $card): BackofficeAboutMeCardResource
    {
        return new BackofficeAboutMeCardResource(
            id: $card->getId(),
            locale: $card->getLocale()->value,
            category: $card->getCategory()->value,
            title: $card->getTitle(),
            description: $card->getDescription(),
            iconKey: $card->getIconKey(),
            position: $card->getPosition(),
        );
    }
}
