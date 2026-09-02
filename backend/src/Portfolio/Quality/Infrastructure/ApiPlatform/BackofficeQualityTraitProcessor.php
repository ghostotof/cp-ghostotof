<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\State\ProcessorInterface;
use App\Portfolio\Quality\Application\QualityTraitAdministratorInterface;
use App\Portfolio\Quality\Domain\Entity\QualityTrait as QualityTraitEntity;
use App\Portfolio\Quality\Presentation\ApiResource\BackofficeQualityTraitResource;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables;

/**
 * @implements ProcessorInterface<BackofficeQualityTraitResource, BackofficeQualityTraitResource|null>
 */
final readonly class BackofficeQualityTraitProcessor implements ProcessorInterface
{
    use ResolvesUriVariables;

    public function __construct(
        private QualityTraitAdministratorInterface $qualityTraitAdministrator,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?BackofficeQualityTraitResource
    {
        if ($operation instanceof Delete) {
            $this->qualityTraitAdministrator->delete($this->uriVariableInt($uriVariables, 'id'));

            return null;
        }

        if ($operation instanceof Put) {
            $trait = $this->qualityTraitAdministrator->update(
                $this->uriVariableInt($uriVariables, 'id'),
                $data->label,
                $data->position,
            );
        } elseif ($operation instanceof Post) {
            $trait = $this->qualityTraitAdministrator->create(
                Locale::from((string) $data->locale),
                $data->label,
                $data->position,
            );
        } else {
            throw new \LogicException(sprintf('Opération non gérée : %s.', $operation::class));
        }

        return $this->toResource($trait);
    }

    private function toResource(QualityTraitEntity $trait): BackofficeQualityTraitResource
    {
        return new BackofficeQualityTraitResource(
            id: $trait->getId(),
            locale: $trait->getLocale()->value,
            label: $trait->getLabel(),
            position: $trait->getPosition(),
        );
    }
}
