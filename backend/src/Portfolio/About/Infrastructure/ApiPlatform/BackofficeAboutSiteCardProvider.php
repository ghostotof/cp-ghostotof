<?php

declare(strict_types=1);

namespace App\Portfolio\About\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Portfolio\About\Domain\Repository\AboutSiteCardRepositoryInterface;
use App\Portfolio\About\Presentation\ApiResource\BackofficeAboutSiteCardResource;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables;
use Symfony\Component\HttpFoundation\Request;

/**
 * @implements ProviderInterface<BackofficeAboutSiteCardResource>
 */
final readonly class BackofficeAboutSiteCardProvider implements ProviderInterface
{
    use ResolvesUriVariables;

    public function __construct(
        private AboutSiteCardRepositoryInterface $aboutSiteCardRepository,
    ) {
    }

    /**
     * @return BackofficeAboutSiteCardResource|list<BackofficeAboutSiteCardResource>|null
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): BackofficeAboutSiteCardResource|array|null
    {
        if ($operation instanceof GetCollection) {
            $request = $context['request'] ?? null;
            \assert($request instanceof Request);

            $locale = Locale::tryFrom($request->query->get('locale', ''));

            $cards = null !== $locale
                ? $this->aboutSiteCardRepository->findByLocale($locale)
                : $this->aboutSiteCardRepository->findAll();

            return array_map(BackofficeAboutSiteCardResource::fromEntity(...), $cards);
        }

        $card = $this->aboutSiteCardRepository->findOneById($this->uriVariableInt($uriVariables, 'id'));

        return null !== $card ? BackofficeAboutSiteCardResource::fromEntity($card) : null;
    }
}
