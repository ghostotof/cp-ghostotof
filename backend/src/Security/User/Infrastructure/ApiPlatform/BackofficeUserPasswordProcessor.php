<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Security\User\Application\CpgUserAdministratorInterface;
use App\Security\User\Presentation\ApiResource\BackofficeUserPasswordResource;

/**
 * @implements ProcessorInterface<BackofficeUserPasswordResource, null>
 */
final readonly class BackofficeUserPasswordProcessor implements ProcessorInterface
{
    public function __construct(
        private CpgUserAdministratorInterface $cpgUserAdministrator,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        \assert($data instanceof BackofficeUserPasswordResource);

        $this->cpgUserAdministrator->changePassword((int) $uriVariables['id'], $data->password);

        return null;
    }
}
