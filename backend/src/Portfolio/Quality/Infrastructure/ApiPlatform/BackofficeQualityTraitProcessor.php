<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\State\ProcessorInterface;
use App\Portfolio\Quality\Application\QualityTraitAdministratorInterface;
use App\Portfolio\Quality\Presentation\ApiResource\BackofficeQualityTraitResource;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables;
use Symfony\Component\Uid\Uuid;

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
            $this->qualityTraitAdministrator->delete($this->uriVariableUuid($uriVariables));

            return null;
        }

        if ($operation instanceof Put) {
            $trait = $this->qualityTraitAdministrator->update(
                $this->uriVariableUuid($uriVariables),
                $data->label,
                $this->translationGroup($data),
            );
        } elseif ($operation instanceof Post) {
            $trait = $this->qualityTraitAdministrator->create(
                Locale::from((string) $data->locale),
                $data->label,
                $this->translationGroup($data),
            );
        } else {
            throw new \LogicException(sprintf('Opération non gérée : %s.', $operation::class));
        }

        return BackofficeQualityTraitResource::fromEntity($trait);
    }

    /**
     * Le groupe est une chaîne RFC 4122 à la frontière, un Uuid dans le domaine
     * (spec 0003 D7). `Uuid::fromString` accepte d'autres formats, mais
     * `#[Assert\Uuid]` a déjà borné le champ en amont.
     */
    private function translationGroup(BackofficeQualityTraitResource $data): ?Uuid
    {
        return null === $data->translationGroup ? null : Uuid::fromString($data->translationGroup);
    }
}
