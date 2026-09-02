<?php

declare(strict_types=1);

namespace App\Portfolio\About\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\State\ProcessorInterface;
use App\Portfolio\About\Application\AboutMeCardAdministratorInterface;
use App\Portfolio\About\Domain\Entity\AboutMeCard;
use App\Portfolio\About\Domain\ValueObject\AboutMeCardCategory;
use App\Portfolio\About\Presentation\ApiResource\BackofficeAboutMeCardResource;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables;

/**
 * @implements ProcessorInterface<BackofficeAboutMeCardResource, BackofficeAboutMeCardResource|null>
 */
final readonly class BackofficeAboutMeCardProcessor implements ProcessorInterface
{
    use ResolvesUriVariables;

    public function __construct(
        private AboutMeCardAdministratorInterface $aboutMeCardAdministrator,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?BackofficeAboutMeCardResource
    {
        if ($operation instanceof Delete) {
            $this->aboutMeCardAdministrator->delete($this->uriVariableInt($uriVariables, 'id'));

            return null;
        }

        if ($operation instanceof Put) {
            $card = $this->aboutMeCardAdministrator->update(
                $this->uriVariableInt($uriVariables, 'id'),
                $data->title,
                $data->description,
                $data->iconKey,
                $data->position,
            );
        } elseif ($operation instanceof Post) {
            $card = $this->aboutMeCardAdministrator->create(
                Locale::from((string) $data->locale),
                AboutMeCardCategory::from((string) $data->category),
                $data->title,
                $data->description,
                $data->iconKey,
                $data->position,
            );
        } else {
            throw new \LogicException(sprintf('Opération non gérée : %s.', $operation::class));
        }

        return $this->toResource($card);
    }

    private function toResource(AboutMeCard $card): BackofficeAboutMeCardResource
    {
        return new BackofficeAboutMeCardResource(
            id: $card->getId(),
            locale: $card->getLocale()->value,
            category: $card->getCategory()->value,
            title: $card->getTitle(),
            description: $card->getDescription(),
            iconKey: $card->getIconKey(),
            position: $card->getPosition(),
        );
    }
}
