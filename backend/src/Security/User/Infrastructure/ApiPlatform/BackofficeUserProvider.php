<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Security\User\Application\CpgUserAdminPresenterInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Security\User\Presentation\ApiResource\BackofficeUserResource;

/**
 * @implements ProviderInterface<BackofficeUserResource>
 */
final readonly class BackofficeUserProvider implements ProviderInterface
{
    public function __construct(
        private CpgUserRepositoryInterface $cpgUserRepository,
        private CpgUserAdminPresenterInterface $cpgUserAdminPresenter,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): BackofficeUserResource|array|null
    {
        if ($operation instanceof GetCollection) {
            return array_map($this->toResource(...), $this->cpgUserRepository->findAll());
        }

        // Résolution de la ressource existante avant suppression (Delete) :
        // sans provider, API Platform ne saurait pas répondre 404 nativement
        // sur un id inconnu avant même d'atteindre le processor.
        $user = $this->cpgUserRepository->findOneById((int) $uriVariables['id']);

        return null !== $user ? $this->toResource($user) : null;
    }

    private function toResource(CpgUser $user): BackofficeUserResource
    {
        $presented = $this->cpgUserAdminPresenter->present($user);

        return new BackofficeUserResource(
            id: $presented['id'],
            username: $presented['username'],
            roles: $presented['roles'],
        );
    }
}
