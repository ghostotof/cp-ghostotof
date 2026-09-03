<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\User\Application\CpgUserAdminPresenterInterface;
use App\Security\User\Application\CpgUserInviterInterface;
use App\Security\User\Presentation\ApiResource\BackofficeUserInviteInput;
use App\Security\User\Presentation\ApiResource\BackofficeUserResource;

/**
 * POST /api/backoffice/users : délègue l'invitation à CpgUserInviter (création
 * du compte en attente + jeton + e-mail) puis présente le compte créé.
 *
 * @implements ProcessorInterface<BackofficeUserInviteInput, BackofficeUserResource>
 */
final readonly class BackofficeUserInviteProcessor implements ProcessorInterface
{
    public function __construct(
        private CpgUserInviterInterface $cpgUserInviter,
        private CpgUserAdminPresenterInterface $cpgUserAdminPresenter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): BackofficeUserResource
    {
        $user = $this->cpgUserInviter->invite($data->email, Locale::from($data->locale));

        $presented = $this->cpgUserAdminPresenter->present($user);

        return new BackofficeUserResource(
            id: $presented['id'],
            username: $presented['username'],
            roles: $presented['roles'],
            email: $presented['email'],
            status: $presented['status'],
        );
    }
}
