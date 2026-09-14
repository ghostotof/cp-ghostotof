<?php

declare(strict_types=1);

namespace App\Portfolio\About\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\State\ProcessorInterface;
use App\Portfolio\About\Application\AboutSiteCardAdministratorInterface;
use App\Portfolio\About\Presentation\ApiResource\BackofficeAboutSiteCardResource;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<BackofficeAboutSiteCardResource, BackofficeAboutSiteCardResource|null>
 */
final readonly class BackofficeAboutSiteCardProcessor implements ProcessorInterface
{
    use ResolvesUriVariables;

    public function __construct(
        private AboutSiteCardAdministratorInterface $aboutSiteCardAdministrator,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?BackofficeAboutSiteCardResource
    {
        if ($operation instanceof Delete) {
            $this->aboutSiteCardAdministrator->delete($this->uriVariableUuid($uriVariables));

            return null;
        }

        if ($operation instanceof Put) {
            $card = $this->aboutSiteCardAdministrator->update(
                $this->uriVariableUuid($uriVariables),
                $data->title,
                $data->description,
                $data->iconKey,
                $this->translationGroup($data),
            );
        } elseif ($operation instanceof Post) {
            $card = $this->aboutSiteCardAdministrator->create(
                Locale::from((string) $data->locale),
                $data->title,
                $data->description,
                $data->iconKey,
                $this->translationGroup($data),
            );
        } else {
            throw new \LogicException(sprintf('Opération non gérée : %s.', $operation::class));
        }

        return BackofficeAboutSiteCardResource::fromEntity($card);
    }

    /**
     * Le groupe est une chaîne RFC 4122 à la frontière, un Uuid dans le domaine
     * (spec 0003 D7). `Uuid::fromString` accepte d'autres formats, mais
     * `#[Assert\Uuid]` a déjà borné le champ en amont.
     */
    private function translationGroup(BackofficeAboutSiteCardResource $data): ?Uuid
    {
        return null === $data->translationGroup ? null : Uuid::fromString($data->translationGroup);
    }
}
