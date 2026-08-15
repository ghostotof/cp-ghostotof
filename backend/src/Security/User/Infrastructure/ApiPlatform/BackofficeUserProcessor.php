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
        $actingUser = $this->security->getUser();

        // Ne devrait jamais se produire : access_control (^/api/backoffice)
        // exige déjà une authentification en amont. Garde explicite plutôt
        // qu'un cast PHPDoc silencieux, pour éviter un TypeError 500 opaque
        // si cette invariante venait à être violée.
        if (!$actingUser instanceof CpgUser) {
            throw new \LogicException('BackofficeUserProcessor::process() appelé sans utilisateur authentifié.');
        }

        $this->cpgUserAdministrator->delete((int) $uriVariables['id'], $actingUser);

        return null;
    }
}
