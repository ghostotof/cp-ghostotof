<?php

declare(strict_types=1);

namespace App\Portfolio\About\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\State\ProcessorInterface;
use App\Portfolio\About\Application\AboutSiteCardAdministratorInterface;
use App\Portfolio\About\Domain\Entity\AboutSiteCard;
use App\Portfolio\About\Presentation\ApiResource\BackofficeAboutSiteCardResource;
use App\Portfolio\Shared\Domain\ValueObject\Locale;

/**
 * @implements ProcessorInterface<BackofficeAboutSiteCardResource, BackofficeAboutSiteCardResource|null>
 */
final readonly class BackofficeAboutSiteCardProcessor implements ProcessorInterface
{
    public function __construct(
        private AboutSiteCardAdministratorInterface $aboutSiteCardAdministrator,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?BackofficeAboutSiteCardResource
    {
        if ($operation instanceof Delete) {
            $this->aboutSiteCardAdministrator->delete((int) $uriVariables['id']);

            return null;
        }

        \assert($data instanceof BackofficeAboutSiteCardResource);

        if ($operation instanceof Put) {
            $card = $this->aboutSiteCardAdministrator->update(
                (int) $uriVariables['id'],
                $data->title,
                $data->description,
                $data->iconKey,
                $data->position,
            );
        } elseif ($operation instanceof Post) {
            $card = $this->aboutSiteCardAdministrator->create(
                Locale::from((string) $data->locale),
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

    private function toResource(AboutSiteCard $card): BackofficeAboutSiteCardResource
    {
        return new BackofficeAboutSiteCardResource(
            id: $card->getId(),
            locale: $card->getLocale()->value,
            title: $card->getTitle(),
            description: $card->getDescription(),
            iconKey: $card->getIconKey(),
            position: $card->getPosition(),
        );
    }
}
