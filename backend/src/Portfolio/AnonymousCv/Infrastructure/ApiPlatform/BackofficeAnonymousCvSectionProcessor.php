<?php

declare(strict_types=1);

namespace App\Portfolio\AnonymousCv\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\State\ProcessorInterface;
use App\Portfolio\AnonymousCv\Application\AnonymousCvSectionAdministratorInterface;
use App\Portfolio\AnonymousCv\Presentation\ApiResource\BackofficeAnonymousCvSectionResource;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables;

/**
 * @implements ProcessorInterface<BackofficeAnonymousCvSectionResource, BackofficeAnonymousCvSectionResource|null>
 */
final readonly class BackofficeAnonymousCvSectionProcessor implements ProcessorInterface
{
    use ResolvesUriVariables;

    public function __construct(
        private AnonymousCvSectionAdministratorInterface $sectionAdministrator,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?BackofficeAnonymousCvSectionResource
    {
        if ($operation instanceof Delete) {
            $this->sectionAdministrator->delete($this->uriVariableInt($uriVariables, 'id'));

            return null;
        }

        if ($operation instanceof Put) {
            $section = $this->sectionAdministrator->update(
                $this->uriVariableInt($uriVariables, 'id'),
                $data->title,
                $data->skills,
                $data->yearsOfExperience,
                $data->achievements,
                $data->position,
            );
        } elseif ($operation instanceof Post) {
            // Locale::from (et non fromString) : la valeur est déjà bornée en
            // amont par #[Assert\Choice] sur le DTO. Un ValueError ici serait
            // un vrai défaut, et doit remonter en 500 plutôt que d'être
            // déguisé en 404.
            $section = $this->sectionAdministrator->create(
                Locale::from((string) $data->locale),
                $data->title,
                $data->skills,
                $data->yearsOfExperience,
                $data->achievements,
                $data->position,
            );
        } else {
            throw new \LogicException(sprintf('Opération non gérée : %s.', $operation::class));
        }

        return BackofficeAnonymousCvSectionResource::fromEntity($section);
    }
}
