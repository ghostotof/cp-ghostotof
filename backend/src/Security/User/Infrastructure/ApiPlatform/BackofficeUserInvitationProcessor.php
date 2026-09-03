<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\User\Application\CpgUserInviterInterface;
use App\Security\User\Domain\Exception\CpgUserNotFoundException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Security\User\Presentation\ApiResource\BackofficeUserInvitationResource;
use App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables;

/**
 * POST /api/backoffice/users/{id}/invitation : résout l'utilisateur (404 si
 * inconnu) puis délègue le renvoi à CpgUserInviter::reinvite.
 *
 * @implements ProcessorInterface<BackofficeUserInvitationResource, null>
 */
final readonly class BackofficeUserInvitationProcessor implements ProcessorInterface
{
    use ResolvesUriVariables;

    public function __construct(
        private CpgUserRepositoryInterface $cpgUserRepository,
        private CpgUserInviterInterface $cpgUserInviter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $id = $this->uriVariableInt($uriVariables, 'id');
        $user = $this->cpgUserRepository->findOneById($id);

        if (null === $user) {
            throw CpgUserNotFoundException::forId($id);
        }

        $this->cpgUserInviter->reinvite($user, Locale::from($data->locale));

        return null;
    }
}
