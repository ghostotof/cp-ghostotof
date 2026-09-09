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
use App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables;

/**
 * @implements ProviderInterface<BackofficeUserResource>
 */
final readonly class BackofficeUserProvider implements ProviderInterface
{
    use ResolvesUriVariables;

    public function __construct(
        private CpgUserRepositoryInterface $cpgUserRepository,
        private CpgUserAdminPresenterInterface $cpgUserAdminPresenter,
    ) {
    }

    /**
     * @return BackofficeUserResource|list<BackofficeUserResource>|null
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): BackofficeUserResource|array|null
    {
        if ($operation instanceof GetCollection) {
            return array_map($this->present(...), $this->cpgUserRepository->findAll());
        }

        // Résolution de la ressource existante avant suppression (Delete) :
        // sans provider, API Platform ne saurait pas répondre 404 nativement
        // sur un id inconnu avant même d'atteindre le processor.
        $user = $this->cpgUserRepository->findOneById($this->uriVariableInt($uriVariables, 'id'));

        return null !== $user ? $this->present($user) : null;
    }

    /**
     * Deux étapes, et l'ordre importe : le présentateur décide de ce qui est
     * exposé d'un compte, la fabrique se contente de lui donner sa forme de
     * DTO. Les enchaîner ici plutôt que dans la fabrique évite que la
     * présentation aille chercher elle-même l'entité, et garde `fromPresented`
     * utilisable par le Processor d'invitation, qui a déjà le tableau présenté.
     */
    private function present(CpgUser $user): BackofficeUserResource
    {
        return BackofficeUserResource::fromPresented($this->cpgUserAdminPresenter->present($user));
    }
}
