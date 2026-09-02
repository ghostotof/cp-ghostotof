<?php

declare(strict_types=1);

namespace App\Portfolio\Stats\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Portfolio\Stats\Domain\Entity\Stat;
use App\Portfolio\Stats\Domain\Repository\StatRepositoryInterface;
use App\Portfolio\Stats\Presentation\ApiResource\BackofficeStatResource;
use App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables;
use Symfony\Component\HttpFoundation\Request;

/**
 * @implements ProviderInterface<BackofficeStatResource>
 */
final readonly class BackofficeStatProvider implements ProviderInterface
{
    use ResolvesUriVariables;

    public function __construct(
        private StatRepositoryInterface $statRepository,
    ) {
    }

    /**
     * @return BackofficeStatResource|list<BackofficeStatResource>|null
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): BackofficeStatResource|array|null
    {
        if ($operation instanceof GetCollection) {
            $request = $context['request'] ?? null;
            \assert($request instanceof Request);

            $locale = Locale::tryFrom($request->query->get('locale', ''));

            $stats = null !== $locale
                ? $this->statRepository->findByLocale($locale)
                : $this->statRepository->findAll();

            return array_map($this->toResource(...), $stats);
        }

        $stat = $this->statRepository->findOneById($this->uriVariableInt($uriVariables, 'id'));

        return null !== $stat ? $this->toResource($stat) : null;
    }

    private function toResource(Stat $stat): BackofficeStatResource
    {
        return new BackofficeStatResource(
            id: $stat->getId(),
            locale: $stat->getLocale()->value,
            value: $stat->getValue(),
            label: $stat->getLabel(),
            iconKey: $stat->getIconKey(),
            position: $stat->getPosition(),
        );
    }
}
