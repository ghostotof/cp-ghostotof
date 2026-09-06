<?php

declare(strict_types=1);

namespace App\Portfolio\Contribution\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Portfolio\Contribution\Domain\Entity\Contribution;
use App\Portfolio\Contribution\Domain\Repository\ContributionRepositoryInterface;
use App\Portfolio\Contribution\Presentation\ApiResource\BackofficeContributionResource;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables;
use Symfony\Component\HttpFoundation\Request;

/**
 * @implements ProviderInterface<BackofficeContributionResource>
 */
final readonly class BackofficeContributionProvider implements ProviderInterface
{
    use ResolvesUriVariables;

    public function __construct(
        private ContributionRepositoryInterface $contributionRepository,
    ) {
    }

    /**
     * @return BackofficeContributionResource|list<BackofficeContributionResource>|null
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): BackofficeContributionResource|array|null
    {
        if ($operation instanceof GetCollection) {
            $request = $context['request'] ?? null;
            \assert($request instanceof Request);

            // Locale::tryFrom (et non fromString) : un filtre de query string
            // absent ou fantaisiste ne doit pas produire un 404, seulement
            // retomber sur la collection complète.
            $locale = Locale::tryFrom($request->query->get('locale', ''));

            $contributions = null !== $locale
                ? $this->contributionRepository->findByLocale($locale)
                : $this->contributionRepository->findAll();

            return array_map($this->toResource(...), $contributions);
        }

        $contribution = $this->contributionRepository->findOneById($this->uriVariableInt($uriVariables, 'id'));

        return null !== $contribution ? $this->toResource($contribution) : null;
    }

    private function toResource(Contribution $contribution): BackofficeContributionResource
    {
        return new BackofficeContributionResource(
            id: $contribution->getId(),
            locale: $contribution->getLocale()->value,
            title: $contribution->getTitle(),
            project: $contribution->getProject(),
            reference: $contribution->getReference(),
            url: $contribution->getUrl(),
            summary: $contribution->getSummary(),
            body: $contribution->getBody(),
            position: $contribution->getPosition(),
        );
    }
}
