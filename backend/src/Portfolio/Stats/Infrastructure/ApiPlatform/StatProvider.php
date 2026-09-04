<?php

declare(strict_types=1);

namespace App\Portfolio\Stats\Infrastructure\ApiPlatform;

use App\Portfolio\Stats\Domain\Entity\Stat;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Portfolio\Stats\Application\StatPresenterInterface;
use App\Portfolio\Stats\Domain\Repository\StatRepositoryInterface;
use App\Portfolio\Stats\Presentation\ApiResource\StatResource;
use App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables;

/**
 * Relie StatResource (Presentation) au Domain : seule cette classe
 * Infrastructure a le droit de connaître à la fois l'entité Doctrine (via le
 * repository) et la ressource API Platform. Même pattern que
 * App\Portfolio\Experience\Infrastructure\ApiPlatform\ExperienceTechnologyProvider.
 *
 * @implements ProviderInterface<StatResource>
 */
final readonly class StatProvider implements ProviderInterface
{
    use ResolvesUriVariables;

    public function __construct(
        private StatRepositoryInterface $statRepository,
        private StatPresenterInterface $statPresenter,
    ) {
    }

    /**
     * @return list<StatResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $locale = $this->uriVariableLocale($uriVariables);

        return array_map(
            fn (Stat $stat): StatResource => new StatResource(...$this->statPresenter->present($stat)),
            $this->statRepository->findByLocale($locale),
        );
    }
}
