<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Security\User\Application\CpgUserAdministratorInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Presentation\ApiResource\BackofficeUserResource;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProcessorInterface<BackofficeUserResource, null>
 */
final readonly class BackofficeUserProcessor implements ProcessorInterface
{
    public function __construct(
        private CpgUserAdministratorInterface $cpgUserAdministrator,
        private Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        /** @var CpgUser $actingUser */
        $actingUser = $this->security->getUser();

        $this->cpgUserAdministrator->delete((int) $uriVariables['id'], $actingUser);

        return null;
    }
}
