<?php

declare(strict_types=1);

namespace App\Portfolio\CaseStudy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\State\ProcessorInterface;
use App\Portfolio\CaseStudy\Application\CaseStudyAdministratorInterface;
use App\Portfolio\CaseStudy\Presentation\ApiResource\BackofficeCaseStudyResource;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<BackofficeCaseStudyResource, BackofficeCaseStudyResource|null>
 */
final readonly class BackofficeCaseStudyProcessor implements ProcessorInterface
{
    use ResolvesUriVariables;

    public function __construct(
        private CaseStudyAdministratorInterface $caseStudyAdministrator,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?BackofficeCaseStudyResource
    {
        if ($operation instanceof Delete) {
            $this->caseStudyAdministrator->delete($this->uriVariableUuid($uriVariables));

            return null;
        }

        if ($operation instanceof Put) {
            $caseStudy = $this->caseStudyAdministrator->update(
                $this->uriVariableUuid($uriVariables),
                $data->title,
                $data->problem,
                $data->solution,
                $data->tradeoffs,
                $data->measuredResult,
                $this->translationGroup($data),
            );
        } elseif ($operation instanceof Post) {
            // Locale::from (et non fromString) : la valeur est déjà bornée en
            // amont par #[Assert\Choice] sur le DTO. Un ValueError ici serait
            // un vrai défaut, et doit remonter en 500 plutôt que d'être
            // déguisé en 404.
            $caseStudy = $this->caseStudyAdministrator->create(
                Locale::from((string) $data->locale),
                $data->title,
                $data->problem,
                $data->solution,
                $data->tradeoffs,
                $data->measuredResult,
                $this->translationGroup($data),
            );
        } else {
            throw new \LogicException(sprintf('Opération non gérée : %s.', $operation::class));
        }

        return BackofficeCaseStudyResource::fromEntity($caseStudy);
    }

    /**
     * Le groupe est une chaîne RFC 4122 à la frontière, un Uuid dans le domaine
     * (spec 0003 D7). `Uuid::fromString` accepte d'autres formats, mais
     * `#[Assert\Uuid]` a déjà borné le champ en amont.
     */
    private function translationGroup(BackofficeCaseStudyResource $data): ?Uuid
    {
        return null === $data->translationGroup ? null : Uuid::fromString($data->translationGroup);
    }
}
