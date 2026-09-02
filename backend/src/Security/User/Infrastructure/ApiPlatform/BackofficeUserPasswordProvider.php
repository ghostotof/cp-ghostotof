<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Security\User\Presentation\ApiResource\BackofficeUserPasswordResource;
use App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables;

/**
 * Sans provider explicite, le Put de BackofficeUserPasswordResource (DTO non
 * mappé Doctrine) échoue en 404 dès l'étape de lecture préalable d'API
 * Platform, avant même d'atteindre le processor — même problème rencontré
 * sur BackofficeExperienceTechnologyResource (Task #3). Ce provider se
 * contente de vérifier que l'utilisateur ciblé existe (404 sinon) ; le
 * changement de mot de passe lui-même est fait par le processor.
 *
 * @implements ProviderInterface<BackofficeUserPasswordResource>
 */
final readonly class BackofficeUserPasswordProvider implements ProviderInterface
{
    use ResolvesUriVariables;

    public function __construct(
        private CpgUserRepositoryInterface $cpgUserRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?BackofficeUserPasswordResource
    {
        $user = $this->cpgUserRepository->findOneById($this->uriVariableInt($uriVariables, 'id'));

        return null !== $user ? new BackofficeUserPasswordResource() : null;
    }
}
