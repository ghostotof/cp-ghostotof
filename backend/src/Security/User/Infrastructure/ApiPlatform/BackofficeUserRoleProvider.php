<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Security\User\Presentation\ApiResource\BackofficeUserRoleResource;
use App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables;

/**
 * Vérifie seulement que l'utilisateur ciblé existe (404 sinon) avant que le
 * Put n'atteigne le processor — même rôle que BackofficeUserPasswordProvider.
 *
 * @implements ProviderInterface<BackofficeUserRoleResource>
 */
final readonly class BackofficeUserRoleProvider implements ProviderInterface
{
    use ResolvesUriVariables;

    public function __construct(
        private CpgUserRepositoryInterface $cpgUserRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?BackofficeUserRoleResource
    {
        $user = $this->cpgUserRepository->findOneById($this->uriVariableInt($uriVariables, 'id'));

        return null !== $user ? new BackofficeUserRoleResource() : null;
    }
}
