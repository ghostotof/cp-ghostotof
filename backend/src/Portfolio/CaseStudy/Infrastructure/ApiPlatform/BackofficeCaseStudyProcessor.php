<?php

declare(strict_types=1);

namespace App\Portfolio\CaseStudy\Infrastructure\ApiPlatform;

use ApiPlatform\State\ProcessorInterface;
use App\Portfolio\CaseStudy\Application\CaseStudyAdministratorInterface;
use App\Portfolio\CaseStudy\Presentation\ApiResource\BackofficeCaseStudyResource;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Shared\Infrastructure\ApiPlatform\DispatchesWriteOperations;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<BackofficeCaseStudyResource, BackofficeCaseStudyResource|null>
 */
final readonly class BackofficeCaseStudyProcessor implements ProcessorInterface
{
    /** @use DispatchesWriteOperations<BackofficeCaseStudyResource> */
    use DispatchesWriteOperations;

    public function __construct(
        private CaseStudyAdministratorInterface $caseStudyAdministrator,
    ) {
    }

    /**
     * @param BackofficeCaseStudyResource $data
     */
    private function create(mixed $data): BackofficeCaseStudyResource
    {
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

        return BackofficeCaseStudyResource::fromEntity($caseStudy);
    }

    /**
     * @param BackofficeCaseStudyResource $data
     */
    private function update(Uuid $id, mixed $data): BackofficeCaseStudyResource
    {
        $caseStudy = $this->caseStudyAdministrator->update(
            $id,
            $data->title,
            $data->problem,
            $data->solution,
            $data->tradeoffs,
            $data->measuredResult,
            $this->translationGroup($data),
        );

        return BackofficeCaseStudyResource::fromEntity($caseStudy);
    }

    private function delete(Uuid $id): void
    {
        $this->caseStudyAdministrator->delete($id);
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
