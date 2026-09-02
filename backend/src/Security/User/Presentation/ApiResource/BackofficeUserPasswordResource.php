<?php

declare(strict_types=1);

namespace App\Security\User\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Put;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Infrastructure\ApiPlatform\BackofficeUserPasswordProcessor;
use App\Security\User\Infrastructure\ApiPlatform\BackofficeUserPasswordProvider;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Changement de mot de passe d'un utilisateur depuis le backoffice, réservé
 * ROLE_SUPER. Réponse 204 sans corps (`output: false`) : le mot de passe ne
 * doit jamais être renvoyé, même haché.
 */
#[ApiResource(
    shortName: 'BackofficeUserPassword',
    operations: [
        new Put(
            uriTemplate: '/backoffice/users/{id}/password',
            status: 204,
            output: false,
            provider: BackofficeUserPasswordProvider::class,
            processor: BackofficeUserPasswordProcessor::class,
        ),
    ],
)]
final readonly class BackofficeUserPasswordResource
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(min: CpgUser::MIN_PASSWORD_LENGTH, max: CpgUser::MAX_PASSWORD_LENGTH)]
        public string $password = '',
    ) {
    }
}
