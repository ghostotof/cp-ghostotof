<?php

declare(strict_types=1);

namespace App\Portfolio\Contribution\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\State\ProcessorInterface;
use App\Portfolio\Contribution\Application\ContributionAdministratorInterface;
use App\Portfolio\Contribution\Presentation\ApiResource\BackofficeContributionResource;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<BackofficeContributionResource, BackofficeContributionResource|null>
 */
final readonly class BackofficeContributionProcessor implements ProcessorInterface
{
    use ResolvesUriVariables;

    public function __construct(
        private ContributionAdministratorInterface $contributionAdministrator,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?BackofficeContributionResource
    {
        if ($operation instanceof Delete) {
            $this->contributionAdministrator->delete($this->uriVariableUuid($uriVariables));

            return null;
        }

        if ($operation instanceof Put) {
            $contribution = $this->contributionAdministrator->update(
                $this->uriVariableUuid($uriVariables),
                $data->title,
                $data->project,
                $data->reference,
                $data->url,
                $data->summary,
                $data->body,
                $this->translationGroup($data),
            );
        } elseif ($operation instanceof Post) {
            // Locale::from (et non fromString) : la valeur est déjà bornée en
            // amont par #[Assert\Choice] sur le DTO. Un ValueError ici serait
            // un vrai défaut, et doit remonter en 500 plutôt que d'être
            // déguisé en 404.
            $contribution = $this->contributionAdministrator->create(
                Locale::from((string) $data->locale),
                $data->title,
                $data->project,
                $data->reference,
                $data->url,
                $data->summary,
                $data->body,
                $this->translationGroup($data),
            );
        } else {
            throw new \LogicException(sprintf('Opération non gérée : %s.', $operation::class));
        }

        return BackofficeContributionResource::fromEntity($contribution);
    }

    /**
     * Le groupe est une chaîne RFC 4122 à la frontière, un Uuid dans le domaine
     * (spec 0003 D7). `Uuid::fromString` accepte d'autres formats, mais
     * `#[Assert\Uuid]` a déjà borné le champ en amont.
     */
    private function translationGroup(BackofficeContributionResource $data): ?Uuid
    {
        return null === $data->translationGroup ? null : Uuid::fromString($data->translationGroup);
    }
}
