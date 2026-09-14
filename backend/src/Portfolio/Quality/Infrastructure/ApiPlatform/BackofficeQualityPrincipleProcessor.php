<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\State\ProcessorInterface;
use App\Portfolio\Quality\Application\QualityPrincipleAdministratorInterface;
use App\Portfolio\Quality\Presentation\ApiResource\BackofficeQualityPrincipleResource;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<BackofficeQualityPrincipleResource, BackofficeQualityPrincipleResource|null>
 */
final readonly class BackofficeQualityPrincipleProcessor implements ProcessorInterface
{
    use ResolvesUriVariables;

    public function __construct(
        private QualityPrincipleAdministratorInterface $qualityPrincipleAdministrator,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?BackofficeQualityPrincipleResource
    {
        if ($operation instanceof Delete) {
            $this->qualityPrincipleAdministrator->delete($this->uriVariableUuid($uriVariables));

            return null;
        }

        if ($operation instanceof Put) {
            $principle = $this->qualityPrincipleAdministrator->update(
                $this->uriVariableUuid($uriVariables),
                $data->title,
                $data->description,
                $data->iconKey,
                $this->translationGroup($data),
            );
        } elseif ($operation instanceof Post) {
            $principle = $this->qualityPrincipleAdministrator->create(
                Locale::from((string) $data->locale),
                $data->title,
                $data->description,
                $data->iconKey,
                $this->translationGroup($data),
            );
        } else {
            throw new \LogicException(sprintf('Opération non gérée : %s.', $operation::class));
        }

        return BackofficeQualityPrincipleResource::fromEntity($principle);
    }

    /**
     * Le groupe est une chaîne RFC 4122 à la frontière, un Uuid dans le domaine
     * (spec 0003 D7). `Uuid::fromString` accepte d'autres formats, mais
     * `#[Assert\Uuid]` a déjà borné le champ en amont.
     */
    private function translationGroup(BackofficeQualityPrincipleResource $data): ?Uuid
    {
        return null === $data->translationGroup ? null : Uuid::fromString($data->translationGroup);
    }
}
