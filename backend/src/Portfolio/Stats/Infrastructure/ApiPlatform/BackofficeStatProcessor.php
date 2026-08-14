<?php

declare(strict_types=1);

namespace App\Portfolio\Stats\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\State\ProcessorInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Portfolio\Stats\Application\StatAdministratorInterface;
use App\Portfolio\Stats\Domain\Entity\Stat;
use App\Portfolio\Stats\Presentation\ApiResource\BackofficeStatResource;

/**
 * @implements ProcessorInterface<BackofficeStatResource, BackofficeStatResource|null>
 */
final readonly class BackofficeStatProcessor implements ProcessorInterface
{
    public function __construct(
        private StatAdministratorInterface $statAdministrator,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?BackofficeStatResource
    {
        if ($operation instanceof Delete) {
            $this->statAdministrator->delete((int) $uriVariables['id']);

            return null;
        }

        \assert($data instanceof BackofficeStatResource);

        if ($operation instanceof Put) {
            $stat = $this->statAdministrator->update(
                (int) $uriVariables['id'],
                $data->value,
                $data->label,
                $data->iconKey,
                $data->position,
            );
        } elseif ($operation instanceof Post) {
            $stat = $this->statAdministrator->create(
                Locale::from((string) $data->locale),
                $data->value,
                $data->label,
                $data->iconKey,
                $data->position,
            );
        } else {
            throw new \LogicException(sprintf('Opération non gérée : %s.', $operation::class));
        }

        return $this->toResource($stat);
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
