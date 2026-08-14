<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\State\ProcessorInterface;
use App\Portfolio\Quality\Application\QualityPrincipleAdministratorInterface;
use App\Portfolio\Quality\Domain\Entity\QualityPrinciple;
use App\Portfolio\Quality\Presentation\ApiResource\BackofficeQualityPrincipleResource;
use App\Portfolio\Shared\Domain\ValueObject\Locale;

/**
 * @implements ProcessorInterface<BackofficeQualityPrincipleResource, BackofficeQualityPrincipleResource|null>
 */
final readonly class BackofficeQualityPrincipleProcessor implements ProcessorInterface
{
    public function __construct(
        private QualityPrincipleAdministratorInterface $qualityPrincipleAdministrator,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?BackofficeQualityPrincipleResource
    {
        if ($operation instanceof Delete) {
            $this->qualityPrincipleAdministrator->delete((int) $uriVariables['id']);

            return null;
        }

        \assert($data instanceof BackofficeQualityPrincipleResource);

        if ($operation instanceof Put) {
            $principle = $this->qualityPrincipleAdministrator->update(
                (int) $uriVariables['id'],
                $data->title,
                $data->description,
                $data->iconKey,
                $data->position,
            );
        } elseif ($operation instanceof Post) {
            $principle = $this->qualityPrincipleAdministrator->create(
                Locale::from((string) $data->locale),
                $data->title,
                $data->description,
                $data->iconKey,
                $data->position,
            );
        } else {
            throw new \LogicException(sprintf('Opération non gérée : %s.', $operation::class));
        }

        return $this->toResource($principle);
    }

    private function toResource(QualityPrinciple $principle): BackofficeQualityPrincipleResource
    {
        return new BackofficeQualityPrincipleResource(
            id: $principle->getId(),
            locale: $principle->getLocale()->value,
            title: $principle->getTitle(),
            description: $principle->getDescription(),
            iconKey: $principle->getIconKey(),
            position: $principle->getPosition(),
        );
    }
}
