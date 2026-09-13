<?php

declare(strict_types=1);

namespace App\Portfolio\AnonymousCv\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Portfolio\AnonymousCv\Domain\Repository\AnonymousCvSectionRepositoryInterface;
use App\Portfolio\AnonymousCv\Presentation\ApiResource\BackofficeAnonymousCvSectionResource;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables;
use Symfony\Component\HttpFoundation\Request;

/**
 * @implements ProviderInterface<BackofficeAnonymousCvSectionResource>
 */
final readonly class BackofficeAnonymousCvSectionProvider implements ProviderInterface
{
    use ResolvesUriVariables;

    public function __construct(
        private AnonymousCvSectionRepositoryInterface $sectionRepository,
    ) {
    }

    /**
     * @return BackofficeAnonymousCvSectionResource|list<BackofficeAnonymousCvSectionResource>|null
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): BackofficeAnonymousCvSectionResource|array|null
    {
        if ($operation instanceof GetCollection) {
            $request = $context['request'] ?? null;
            \assert($request instanceof Request);

            // Locale::tryFrom (et non fromString) : un filtre de query string
            // absent ou fantaisiste ne doit pas produire un 404, seulement
            // retomber sur la collection complète.
            $locale = Locale::tryFrom($request->query->get('locale', ''));

            $sections = null !== $locale
                ? $this->sectionRepository->findByLocale($locale)
                : $this->sectionRepository->findAll();

            return array_map(BackofficeAnonymousCvSectionResource::fromEntity(...), $sections);
        }

        $section = $this->sectionRepository->findOneById($this->uriVariableInt($uriVariables, 'id'));

        return null !== $section ? BackofficeAnonymousCvSectionResource::fromEntity($section) : null;
    }
}
