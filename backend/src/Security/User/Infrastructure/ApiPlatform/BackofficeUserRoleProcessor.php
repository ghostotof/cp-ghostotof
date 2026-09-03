<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Security\User\Application\CpgUserRoleAdministratorInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Presentation\ApiResource\BackofficeUserRoleResource;
use App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProcessorInterface<BackofficeUserRoleResource, null>
 */
final readonly class BackofficeUserRoleProcessor implements ProcessorInterface
{
    use ResolvesUriVariables;

    public function __construct(
        private CpgUserRoleAdministratorInterface $cpgUserRoleAdministrator,
        private Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $actingUser = $this->security->getUser();

        // access_control (^/api/backoffice) garantit déjà une authentification :
        // garde explicite plutôt qu'un TypeError 500 opaque si l'invariante
        // venait à être violée (même patron que BackofficeUserProcessor).
        if (!$actingUser instanceof CpgUser) {
            throw new \LogicException('BackofficeUserRoleProcessor::process() appelé sans utilisateur authentifié.');
        }

        $this->cpgUserRoleAdministrator->setSuperAdmin(
            $this->uriVariableInt($uriVariables, 'id'),
            $data->superAdmin,
            $actingUser,
        );

        return null;
    }
}
