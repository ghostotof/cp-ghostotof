<?php

declare(strict_types=1);

namespace App\Portfolio\AnonymousCv\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Portfolio\AnonymousCv\Application\AnonymousCvSectionPresenterInterface;
use App\Portfolio\AnonymousCv\Domain\Entity\AnonymousCvSection;
use App\Portfolio\AnonymousCv\Domain\Repository\AnonymousCvSectionRepositoryInterface;
use App\Portfolio\AnonymousCv\Presentation\ApiResource\AnonymousCvSectionResource;
use App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables;

/**
 * @implements ProviderInterface<AnonymousCvSectionResource>
 */
final readonly class AnonymousCvProvider implements ProviderInterface
{
    use ResolvesUriVariables;

    public function __construct(
        private AnonymousCvSectionRepositoryInterface $sectionRepository,
        private AnonymousCvSectionPresenterInterface $sectionPresenter,
    ) {
    }

    /**
     * @return list<AnonymousCvSectionResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $locale = $this->uriVariableLocale($uriVariables);

        return array_map(
            fn (AnonymousCvSection $section): AnonymousCvSectionResource => new AnonymousCvSectionResource(
                ...$this->sectionPresenter->present($section),
            ),
            $this->sectionRepository->findByLocale($locale),
        );
    }
}
