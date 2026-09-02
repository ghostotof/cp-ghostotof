<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Security\User\Application\CpgUserAdministratorInterface;
use App\Security\User\Presentation\ApiResource\BackofficeUserPasswordResource;
use App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables;

/**
 * @implements ProcessorInterface<BackofficeUserPasswordResource, null>
 */
final readonly class BackofficeUserPasswordProcessor implements ProcessorInterface
{
    use ResolvesUriVariables;

    public function __construct(
        private CpgUserAdministratorInterface $cpgUserAdministrator,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $this->cpgUserAdministrator->changePassword($this->uriVariableInt($uriVariables, 'id'), $data->password);

        return null;
    }
}
