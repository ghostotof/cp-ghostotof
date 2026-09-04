<?php

declare(strict_types=1);

namespace App\Security\User\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Put;
use App\Security\User\Infrastructure\ApiPlatform\BackofficeUserRoleProcessor;
use App\Security\User\Infrastructure\ApiPlatform\BackofficeUserRoleProvider;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Promotion / rétrogradation du rôle ROLE_SUPER d'un compte depuis le
 * backoffice, réservé ROLE_SUPER. Réponse 204 sans corps (`output: false`).
 *
 * `provider` explicite obligatoire pour un Put sur un DTO non mappé Doctrine
 * (sinon 404 à l'étape de lecture d'API Platform, cf. BackofficeUserPasswordResource).
 */
#[ApiResource(
    shortName: 'BackofficeUserRole',
    operations: [
        new Put(
            uriTemplate: '/backoffice/users/{id}/roles',
            status: 204,
            output: false,
            provider: BackofficeUserRoleProvider::class,
            processor: BackofficeUserRoleProcessor::class,
        ),
    ],
)]
final readonly class BackofficeUserRoleResource
{
    public function __construct(
        // Nullable + NotNull : un corps sans `superAdmin` échoue en 422 plutôt
        // que d'être interprété comme une rétrogradation implicite.
        #[Assert\NotNull]
        public ?bool $superAdmin = null,
    ) {
    }
}
