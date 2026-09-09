<?php

declare(strict_types=1);

namespace App\Portfolio\Incident\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Portfolio\Incident\Domain\Repository\IncidentRepositoryInterface;
use App\Portfolio\Incident\Presentation\ApiResource\BackofficeIncidentResource;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables;
use Symfony\Component\HttpFoundation\Request;

/**
 * @implements ProviderInterface<BackofficeIncidentResource>
 */
final readonly class BackofficeIncidentProvider implements ProviderInterface
{
    use ResolvesUriVariables;

    public function __construct(
        private IncidentRepositoryInterface $incidentRepository,
    ) {
    }

    /**
     * @return BackofficeIncidentResource|list<BackofficeIncidentResource>|null
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): BackofficeIncidentResource|array|null
    {
        if ($operation instanceof GetCollection) {
            $request = $context['request'] ?? null;
            \assert($request instanceof Request);

            // tryFrom et non fromString : un filtre absent ou fantaisiste
            // retombe sur la collection complète, il ne produit pas un 404.
            $locale = Locale::tryFrom($request->query->get('locale', ''));

            $incidents = null !== $locale
                ? $this->incidentRepository->findByLocale($locale)
                : $this->incidentRepository->findAll();

            return array_map(BackofficeIncidentResource::fromEntity(...), $incidents);
        }

        $incident = $this->incidentRepository->findOneById($this->uriVariableInt($uriVariables, 'id'));

        return null !== $incident ? BackofficeIncidentResource::fromEntity($incident) : null;
    }
}
