<?php

declare(strict_types=1);

namespace App\Portfolio\About\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Portfolio\About\Application\AboutMeCardAdministratorInterface;
use App\Portfolio\About\Presentation\ApiResource\BackofficeAboutMeCardOrderResource;

/**
 * Le processor ne fait que traduire : la règle d'ensemble exact (spec 0004 D4)
 * et la renumérotation vivent dans OrderAssigner, la transaction dans
 * l'Administrator — qui ne charge ici que la catégorie visée, si bien que les
 * deux autres ne peuvent pas être touchées.
 *
 * @implements ProcessorInterface<BackofficeAboutMeCardOrderResource, null>
 */
final readonly class BackofficeAboutMeCardOrderProcessor implements ProcessorInterface
{
    public function __construct(
        private AboutMeCardAdministratorInterface $aboutMeCardAdministrator,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $this->aboutMeCardAdministrator->reorder($data->validatedCategory(), $data->keys());

        return null;
    }
}
